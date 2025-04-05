<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;

class IndenterTest extends \PHPUnit\Framework\TestCase {
    public function testInvalidSetupOption (): void {
        $this->expectException(\Gajus\Dindent\Exception\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unrecognized option.');
        new \Gajus\Dindent\Indenter(array('foo' => 'bar'));
    }

    public function testIndentCustomCharacter (): void {
        $indenter = new \Gajus\Dindent\Indenter(array('indentation_character' => 'X'));

        $indented = $indenter->indent('<p><p></p></p>');

        $expected_output = '<p>X<p></p></p>';

        $this->assertSame($expected_output, str_replace("\n", '', $indented));
    }

    #[DataProvider('logProvider')]
    public function testLog ($token, $log): void {
        $indenter = new \Gajus\Dindent\Indenter([ 'logging' => true ]);
        $indenter->indent($token);

        $this->assertSame(array($log), $indenter->getLog());
    }

    public static function logProvider(): array {
        return [
            [
                '<p></p>',
                [
                    'rule' => 'NO',
                    'pattern' => '/^(<([a-z]+)(?:[^>]*)>(?:[^<]*)<\\/(?:\\2)>)/',
                    'match' => '<p></p>',
                    'subject' => '<p></p>',
                ]
            ]
        ];
    }

    #[DataProvider('indentProvider')]
    public function testIndent ($name): void {
        $indenter = new \Gajus\Dindent\Indenter();

        $input = file_get_contents(__DIR__ . '/sample/input/' . $name . '.html');
        $expected_output = file_get_contents(__DIR__ . '/sample/output/' . $name . '.html');

        $this->assertSame($expected_output, $indenter->indent($input));
    }

    public static function indentProvider():array {
        return array_map(function ($e) {
            return array(pathinfo($e, \PATHINFO_FILENAME));
        }, glob(__DIR__ . '/sample/input/*.html'));
    }
}
