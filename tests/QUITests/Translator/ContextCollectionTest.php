<?php

namespace QUITests\Translator;

use PHPUnit\Framework\TestCase;
use QUI\Translator\ContextCollection;

class ContextCollectionTest extends TestCase
{
    public function testStandardAndCustomContextsUseOneEffectiveValue(): void
    {
        $rows = [];

        foreach (
            [
            'shared' => '',
            'front' => 'frontend',
            'admin' => 'administration',
            'both' => 'frontend,administration',
            'custom' => 'namescoreDashboard',
            'multi' => 'administration,namescoreDashboard,otherDashboard'
            ] as $key => $context
        ) {
            $rows[] = [
                'groups' => 'test/base',
                'var' => $key,
                'datatype' => 'js',
                'context' => $context,
                'de' => $key,
                'en' => 'en-' . $key
            ];
        }

        $result = ContextCollection::build($rows, ['de', 'en']);
        $modules = $result['modules']['de'];
        self::assertSame(['shared' => 'shared', 'both' => 'both'], $modules['common']['test/base']);
        self::assertSame(['front' => 'front'], $modules['frontend']['test/base']);
        self::assertSame(['admin' => 'admin', 'multi' => 'multi'], $modules['administration']['test/base']);
        self::assertSame(['custom' => 'custom', 'multi' => 'multi'], $modules['namescoreDashboard']['test/base']);
        self::assertSame(['multi' => 'multi'], $modules['otherDashboard']['test/base']);
        self::assertCount(6, $result['legacy']['de']['test/base']);
        self::assertSame('en-custom', $result['modules']['en']['namescoreDashboard']['test/base']['custom']);
    }

    public function testTextOverrideInheritsContextAcrossLanguagesAndUsesIndividualText(): void
    {
        $rows = [
            [
                'groups' => 'test/base', 'var' => 'title', 'package' => 'test/base',
                'priority' => 0, 'datatype' => 'php,js', 'context' => 'namescoreDashboard',
                'de' => 'Basis', 'en' => 'Base'
            ],
            [
                'groups' => 'test/base', 'var' => 'title', 'package' => 'test/override',
                'priority' => 5, 'datatype' => 'php,js', 'context' => null,
                'de' => 'Override', 'de_edit' => 'Individual', 'en' => ''
            ]
        ];
        $result = ContextCollection::build(array_reverse($rows), ['de', 'en']);
        self::assertSame([], $result['modules']['de']['common']);
        self::assertSame('Individual', $result['modules']['de']['namescoreDashboard']['test/base']['title']);
        self::assertSame('Base', $result['modules']['en']['namescoreDashboard']['test/base']['title']);
        self::assertSame($result, ContextCollection::build($rows, ['de', 'en']));
    }

    public function testExplicitEmptyOverrideResetsContextAndRemovedContextsRemainKnown(): void
    {
        $rows = [
            [
                'groups' => 'test/base', 'var' => 'title', 'priority' => 0,
                'context' => 'namescoreDashboard', 'de' => 'Base'
            ],
            [
                'groups' => 'test/base', 'var' => 'title', 'priority' => 5,
                'context' => '', 'de' => 'Override'
            ],
            [
                'groups' => 'test/base', 'var' => 'php', 'datatype' => 'php',
                'context' => 'phpOnly', 'de' => 'Server only'
            ]
        ];
        $result = ContextCollection::build($rows, ['de'], ['removedContext']);
        self::assertSame(['title' => 'Override'], $result['modules']['de']['common']['test/base']);
        self::assertSame([], $result['modules']['de']['namescoreDashboard']);
        self::assertSame([], $result['modules']['de']['removedContext']);
        self::assertSame([], $result['modules']['de']['phpOnly']);
        self::assertArrayNotHasKey('php', $result['legacy']['de']['test/base']);
    }

    public function testLegacyEmptyTextAndIndividualFallbackSemanticsArePreserved(): void
    {
        $rows = [];

        foreach (
            [
            'empty' => '',
            'null' => null,
            'zero' => '0',
            'space' => ' ',
            'fallback' => 'Original'
            ] as $key => $value
        ) {
            $rows[] = [
                'groups' => 'test/base',
                'var' => $key,
                'de' => $value,
                'de_edit' => '0',
                'context' => null
            ];
        }

        $result = ContextCollection::build($rows, ['de']);
        self::assertSame(['space' => ' ', 'fallback' => 'Original'], $result['legacy']['de']['test/base']);
        self::assertSame($result['legacy']['de'], $result['modules']['de']['common']);
    }

    public function testOverlappingContextsContainTheSameWinningValue(): void
    {
        $rows = [
            [
                'groups' => 'test/base', 'var' => 'title', 'package' => 'test/base',
                'priority' => 0, 'context' => 'frontend,namescoreDashboard,otherDashboard',
                'de' => 'Original', 'en' => 'Original English'
            ],
            [
                'groups' => 'test/base', 'var' => 'title', 'package' => 'test/override',
                'priority' => 10, 'context' => null, 'de' => 'Override',
                'de_edit' => 'Individual winner', 'en' => 'English winner'
            ]
        ];
        $result = ContextCollection::build($rows, ['de', 'en']);

        foreach (['de', 'en'] as $language) {
            $modules = $result['modules'][$language];
            self::assertSame($result['legacy'][$language], $modules['frontend']);
            self::assertSame($modules['frontend'], $modules['namescoreDashboard']);
            self::assertSame($modules['namescoreDashboard'], $modules['otherDashboard']);
        }
    }

    public function testEqualPriorityUsesStablePackageThenIdOrder(): void
    {
        $base = ['groups' => 'test/base', 'var' => 'title', 'priority' => 0];
        $rows = [
            $base + ['id' => 2, 'package' => 'a/base', 'de' => 'a'],
            $base + ['id' => 1, 'package' => 'z/base', 'de' => 'z-first'],
            $base + ['id' => 3, 'package' => 'z/base', 'de' => 'z-last']
        ];
        $result = ContextCollection::build($rows, ['de']);
        self::assertSame('z-last', $result['legacy']['de']['test/base']['title']);
        self::assertSame($result, ContextCollection::build(array_reverse($rows), ['de']));
    }
}
