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

    // https://developer.mozilla.org/en-US/docs/Glossary/Void_element
    private array $void_elements = [
        'area','base','br','col','embed','hr','img',
        'input','link','meta','source','track','wbr'
    ];
    // https://developer.mozilla.org/en-US/docs/Web/HTML/Element#inline_text_semantics
    private array $inline_elements = [
        'a', 'abbr', 'b', 'bdi', 'bdo', 'big', 'cite',
        'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark',
        'q', 's', 'samp', 'small', 'span', 'strong',
        'sub', 'sup', 'time', 'u', 'var', 'acronym','tt'
    ];

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
                return $match['elm'].'ᐄᐄᐄ'.$count++;
            },
            $input
        );

        // Shrink global whitespace
        $input = preg_replace('/\h+/', ' ', $input);
        // Remove leading/trailing spaces
        $input = preg_replace('/^ | $/m', '', $input);

        // Temporary remove inline elements
        $count = 0;
        $input = preg_replace_callback(
            '/\s*(?<elm><('.implode('|', $this->inline_elements).')[^>]*>)\s*(?<str>[^<]*?)\s*(?<clt><\/\2>)\s*/i',
            function ($match) use (&$count): string
            {
                if(empty($match['str'])) {
                    return $match[0];
                }
                $this->temporary_replacements_inline[] = sprintf(' %s%s%s ', $match['elm'], $match['str'], $match['clt']);
                return 'ᐃᐃᐃ'.$count++;
            },
            $input
        );

        // Discard useless whitespace
        $input = preg_replace('/(<[^>]+>)\s+(?=<)/', '$1', ltrim($input));

        // NO line-breake mode!
        if(null === $this->options['indentation_character']) {
            $this->options['logging'] = false;// HACK

            $subject  = null;
            $output   = $input;
        } else {
            $output   = '';
            $subject  = preg_replace_callback(
                '/<!DOCTYPE[^>]+>/i',
                function ($match) use (&$output): string
                {
                    $output = $match[0]."\n";
                    return '';
                },
                $input
            );

            $indLen = -1*strlen($this->options['indentation_character']);
            $indent   = '';
            $patterns = [
                // comment
                '/^<!--[\s\S]*?-->/' => MatchType::IndentKeep,
                // standart element
                '/^<([a-z][\w\-]*)(?: [^<]*)?>[^<]*<\/\1>/' => MatchType::IndentKeep,
                // implied closing
                '/^<(?:'.implode('|', $this->void_elements).')[^>]*>/' => MatchType::IndentKeep,
                // self-closing
                '/^<[^>]+\/>/' => MatchType::IndentKeep,

                // closing tag
                '/^<\/[^>]+>/' => MatchType::IndentDecrease,
                // opening tag
                '/^<[^>]+>/' => MatchType::IndentIncrease,
                // text node
                '/^[^<]+/' => MatchType::IndentKeep
            ];
        }
        while($subject) {
            foreach ($patterns as $pattern => $rule) {
                if (preg_match($pattern, $subject, $matches)) {// TODO; check speed `PREG_OFFSET_CAPTURE` vs `mb_strlen()`
                    if ($this->options['logging']) {
                        $this->log[] = [
                            'rule'    => $rule->asString(),
                            'pattern' => $pattern,
                            'match'   => $matches[0],
                            'subject' => $subject
                        ];
                    }

                    $subject = mb_substr($subject, mb_strlen($matches[0]));

                    switch($rule) {
                        case MatchType::IndentIncrease:
                            $output .= $indent.$matches[0]."\n";
                            $indent .= $this->options['indentation_character'];
                            break 2;

                        case MatchType::IndentDecrease:
                            $indent = substr($indent, 0, $indLen);

                        case MatchType::IndentKeep:
                            $output .= $indent.$matches[0]."\n";
                            break 2;

                        default:
                            throw new Exception\RuntimeException("MatchType?:{$rule}");
                    }
                    throw new Exception\RuntimeException("MissedMatch!:{$matches[0]}");
                }
            }
        }

        if ($this->options['logging']) {
            $interpreted_input = '';
            foreach ($this->log as $e) {
                $interpreted_input .= $e['match'];
            }

            if ($interpreted_input !== $input) {
                if(!empty($this->log)) var_dump($this->log);
                throw new Exception\RuntimeException("\n{$interpreted_input}\n!==\n{$input}\n");
            }
        }

        // Restore inline elements
        foreach ($this->temporary_replacements_inline as $i => $original) {
            $output = str_replace('ᐃᐃᐃ'.$i, $original, $output);
        }

        // Remove empty space inside & between tags
        $output = preg_replace('/(<[^>]+>) (?=<\/)/', '$1', $output);

        // Restore `<script|style>` bodys
        foreach ($this->temporary_replacements_source as $i => $original) {
          $output = preg_replace('/(\s*)(<[^>]+>)ᐄᐄᐄ'.$i.'/', '$1$2'.$original['str'].($original['lf'] ? "\n$1" : ''), $output);
        }

        return rtrim($output);
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
