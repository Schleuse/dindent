<?php

declare(strict_types=1);

namespace Gajus\Dindent;

/**
 * @link https://github.com/gajus/dindent for the canonical source repository
 * @license https://github.com/gajus/dindent/blob/master/LICENSE BSD 3-Clause
 *
 * @phpstan-type LogEntry array{rule: string, pattern: string, subject: string, match: string}
 * @phpstan-type Options array{indentation_character: string, logging: boolean}
 */
class Indenter
{
    /**
     * @var LogEntry[]
     */
    private array $log = [];

    /**
     * @var Options[]
     */
    private array $options = [
        'indentation_character' => '    ',
        'logging' => false
    ];

    /**
     * https://developer.mozilla.org/en-US/docs/Web/HTML/Element#inline_text_semantics
     */
    private array $inline_elements =  ['b', 'big', 'i', 's', 'small', 'tt', 'q', 'u', 'abbr', 'acronym', 'cite', 'code', 'data', 'dfn', 'em', 'kbd', 'mark', 'strong', 'samp', 'time', 'var', 'a', 'bdi', 'bdo', 'br', 'img', 'span', 'sub', 'sup', 'wbr'];

    private array $temporary_replacements_source = [];
    private array $temporary_replacements_inline = [];

    /**
     * @param Options[] $options
     */
    public function __construct (array $options = []) {
        foreach ($options as $name => $value) {
            if (!array_key_exists($name, $this->options)) {
                throw new Exception\InvalidArgumentException('Unrecognized option.');
            }

            $this->options[$name] = $value;
        }
    }

    /**
     * @param string $element_name Element name, e.g. "b".
     * @param ElementType $type
     */
    public function setElementType (string $element_name, ElementType $type): void {
        if ($type === ElementType::Block) {
            $this->inline_elements = array_diff($this->inline_elements, [$element_name]);
        } else if ($type === ElementType::Inline) {
            $this->inline_elements[] = $element_name;
        } else {
            throw new Exception\InvalidArgumentException('Unrecognized element type.');
        }
        $this->inline_elements = array_unique($this->inline_elements);
    }

    /**
     * @param string $input HTML input.
     * @return string Indented HTML.
     */
    public function indent(string $input): string {
        $this->log = [];

        // Dindent does not indent `<script|style>` body. Instead, it temporary removes it from the code, indents the input, and restores the body.
        $count = 0;
        $input = preg_replace_callback(
            '/(?<elm><(script|style)[^>]*>)(?<str>[\s\S]*?)(?<lf>\n?)\s*(?=<\/\2>)/i',
            function ($match) use (&$count): string
            {
                if(empty($match['str'])) {
                    return $match[0];
                }
                $this->temporary_replacements_source[] = $match;
                return $match['elm'].'ᐄᐄᐄ' . $count++ . 'ᐄᐄᐄ';
            },
            $input
        );

        // Removing double whitespaces to make the source code easier to read.
        // With exception of <pre>/ CSS white-space changing the default behaviour, double whitespace is meaningless in HTML output.
        // This reason alone is sufficient not to use Dindent in production.
        $input = str_replace("\t", '', $input);
        $input = preg_replace('/\s{2,}/u', ' ', $input);

        // Remove inline elements and replace them with text entities.
        $count = 0;
        $input = preg_replace_callback(
            '/\s*(?<elm><(' . implode('|', $this->inline_elements) . ')[^>]*>)\s*(?<str>[^<]*?)\s*(?<clt><\/\2>)\s*/i',
            function ($match) use (&$count): string
            {
                if(empty($match['str'])) {
                    return $match[0];
                }
                $this->temporary_replacements_inline[] = sprintf(' %s%s%s ', $match['elm'], $match['str'], $match['clt']);
                return 'ᐃᐃᐃ' . $count++ . 'ᐃᐃᐃ';
            },
            $input
        );


        $subject  = $input;
        $output   = '';

        $next_line_indentation_level = 0;

        do {
            $indentation_level = $next_line_indentation_level;

            $patterns = [
                // block tag
                '/^(<([a-z]+)(?:[^>]*)>(?:[^<]*)<\/(?:\2)>)/' => MatchType::NoIndent,
                // DOCTYPE
                '/^<!([^>]*)>/' => MatchType::NoIndent,
                // tag with implied closing @ https://developer.mozilla.org/en-US/docs/Glossary/Void_element
                '/^<(area|base|br|col|embed|hr|img|input|link|meta|source|track|wbr)([^>]*)>/' => MatchType::NoIndent,
                // (most) self closing SVG tags @ https://developer.mozilla.org/en-US/docs/Web/SVG/Reference/Element#svg_elements_by_category
                '/^<(animate|circle|ellipse|line|path|polygon|polyline|rect|stop|use)([^>]*)\/>/' => MatchType::NoIndent,

                // opening tag
                '/^<[^\/]([^>]*)>/' => MatchType::IndentIncrease,
                // closing tag
                '/^<\/([^>]*)>/' => MatchType::IndentDecrease,
                // self-closing tag
                '/^<(.+)\/>/' => MatchType::IndentDecrease,
                // whitespace
                '/^(\s+)/' => MatchType::Discard,
                // text node
                '/([^<]+)/' => MatchType::NoIndent
            ];

            foreach ($patterns as $pattern => $rule) {
                if ($match = preg_match($pattern, $subject, $matches)) {
                    if ($this->options['logging']) {
                        $this->log[] = [
                            'rule'    => $rule->asString(),
                            'pattern' => $pattern,
                            'match'   => $matches[0],
                            'subject' => $subject
                        ];
                    }

                    $subject = mb_substr($subject, mb_strlen($matches[0]));

                    if ($rule === MatchType::Discard) {
                        break;
                    }

                    if ($rule === MatchType::NoIndent) {

                    } else if ($rule === MatchType::IndentDecrease) {
                        $next_line_indentation_level--;
                        $indentation_level--;
                    } else {
                        $next_line_indentation_level++;
                    }

                    if ($indentation_level < 0) {
                        $indentation_level = 0;
                    }

                    $output .= str_repeat($this->options['indentation_character'], $indentation_level) . $matches[0] . "\n";

                    break;
                }
            }
        } while ($match);

        if ($this->options['logging']) {
            $interpreted_input = '';
            foreach ($this->log as $e) {
                $interpreted_input .= $e['match'];
            }

            if ($interpreted_input !== $input) {
                throw new Exception\RuntimeException('Did not reproduce the exact input.');
            }
        }

        // Restore inline elements
        foreach ($this->temporary_replacements_inline as $i => $original) {
            $output = str_replace('ᐃᐃᐃ'.$i.'ᐃᐃᐃ', $original, $output);
        }

        // Remove empty space inside & between tags
        $output = preg_replace('/(<(\w+)[^>]*>)\s*(<\/\2>)|(?<=>) (?=<)/u', '$1$3', $output);

        // Restore `<script|style>` bodys
        foreach ($this->temporary_replacements_source as $i => $original) {
            $output = preg_replace('/(\s*)(<(\w+)[^>]*>)ᐄᐄᐄ'.$i.'ᐄᐄᐄ(?=<\/\3>)/', '$1$2'.$original['str'].($original['lf'] ? "\n$1" : ''), $output);
        }

        return trim($output);
    }

    /**
     * Debugging utility. Get log for the last indent operation.
     *
     * @return LogEntry[]
     */
    public function getLog(): array {
        return $this->log;
    }
}
