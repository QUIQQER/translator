<?php

namespace QUITests\Translator;

use PHPUnit\Framework\TestCase;
use QUI\Translator;

class TranslatorTest extends TestCase
{
    public function testGetTBlocksFromStringWithAttributes(): void
    {
        $string = '{t groups="quiqqer/translator" var="message.test"}ignored{/t}';

        $result = Translator::getTBlocksFromString($string);

        $this->assertSame([
            [
                'groups' => 'quiqqer/translator',
                'var' => 'message.test'
            ]
        ], $result);
    }

    public function testGetTBlocksFromStringWithGroupVarText(): void
    {
        $string = '{t}quiqqer/translator message.test{/t}';

        $result = Translator::getTBlocksFromString($string);

        $this->assertSame([
            [
                'groups' => 'quiqqer/translator',
                'var' => 'message.test'
            ]
        ], $result);
    }

    public function testGetLBlocksFromStringFindsLocaleCalls(): void
    {
        $string = <<<'PHP'
<?php
echo $L->get('quiqqer/translator', 'message.one');
echo $Locale->get('quiqqer/translator', 'message.two');
echo $L->get('translator', 'message.three');
echo $Locale->get('translator', 'message.four');
PHP;

        $result = Translator::getLBlocksFromString($string);

        $this->assertSame([
            [
                'groups' => 'translator',
                'var' => 'message.three'
            ],
            [
                'groups' => 'translator',
                'var' => 'message.four'
            ]
        ], $result);
    }

    public function testDeleteDoubleEntriesRemovesDuplicatesByGroupAndVar(): void
    {
        $entries = [
            ['groups' => 'a/b', 'var' => 'x'],
            ['groups' => 'a/b', 'var' => 'x'],
            ['groups' => 'a/b', 'var' => 'y']
        ];

        $result = Translator::deleteDoubleEntries($entries);

        $this->assertCount(2, $result);
        $this->assertSame([
            ['groups' => 'a/b', 'var' => 'x'],
            ['groups' => 'a/b', 'var' => 'y']
        ], $result);
    }
}
