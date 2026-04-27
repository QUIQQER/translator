<?php

namespace QUITests\Translator;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\Translator;
use ReflectionClass;

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

    public function testLocalePublishVersionIsPersistedInPackageConfig(): void
    {
        $Package = QUI::getPackage('quiqqer/translator');
        $Config = $Package->getConfig();

        if (!$Config) {
            $this->markTestSkipped('Package config is not available.');
        }

        $oldVersion = (string)$Config->get('locale', 'publishVersion');
        $RefClass = new ReflectionClass(Translator::class);
        $versionProperty = $RefClass->getProperty('localePublishVersion');
        $versionProperty->setValue(null);

        try {
            $version1 = Translator::getLocalePublishVersion();
            $this->assertNotSame('', $version1);

            $versionProperty->setValue(null);
            $version2 = Translator::getLocalePublishVersion();
            $this->assertSame($version1, $version2);
        } finally {
            $Config->set('locale', 'publishVersion', $oldVersion);
            $Config->save();
            $versionProperty->setValue(null);
        }
    }

    public function testRefreshLocalePublishVersionWritesNewConfigValue(): void
    {
        $Package = QUI::getPackage('quiqqer/translator');
        $Config = $Package->getConfig();

        if (!$Config) {
            $this->markTestSkipped('Package config is not available.');
        }

        $oldVersion = (string)$Config->get('locale', 'publishVersion');
        $RefClass = new ReflectionClass(Translator::class);
        $versionProperty = $RefClass->getProperty('localePublishVersion');
        $refreshMethod = $RefClass->getMethod('refreshLocalePublishVersion');
        $versionProperty->setValue(null);

        try {
            $versionBefore = Translator::getLocalePublishVersion();
            usleep(1000);
            $refreshMethod->invoke(null);

            $versionProperty->setValue(null);
            $versionAfter = Translator::getLocalePublishVersion();

            $this->assertNotSame($versionBefore, $versionAfter);
        } finally {
            $Config->set('locale', 'publishVersion', $oldVersion);
            $Config->save();
            $versionProperty->setValue(null);
        }
    }
}
