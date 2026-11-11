<?php

namespace QUI\Translator;

use QUI\Utils\LocaleContext;

/** Builds every JavaScript view from the same effective translation values. */
final class ContextCollection
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $languages
     * @param list<string> $knownContexts
     * @return array{contexts: list<string>, legacy: array<string, array<string, array<string, string>>>,
     *     modules: array<string, array<string, array<string, array<string, string>>>>}
     */
    public static function build(array $rows, array $languages, array $knownContexts = []): array
    {
        usort($rows, static function (array $left, array $right): int {
            return ((int)($left['priority'] ?? 0) <=> (int)($right['priority'] ?? 0))
                ?: strcmp((string)($left['package'] ?? ''), (string)($right['package'] ?? ''))
                ?: ((int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0));
        });

        $contexts = array_fill_keys(array_merge(['frontend', 'administration'], $knownContexts), true);
        $assignments = [];
        $legacy = array_fill_keys($languages, []);

        foreach ($rows as $row) {
            $group = (string)$row['groups'];
            $key = (string)$row['var'];

            if (isset($row['context'])) {
                $context = LocaleContext::normalize($row['context']);
                $assignments[$group][$key] = $context === '' ? [] : explode(',', $context);

                foreach ($assignments[$group][$key] as $name) {
                    $contexts[$name] = true;
                }
            }

            $datatype = (string)($row['datatype'] ?? '');

            if ($datatype !== '' && !str_contains($datatype, 'js')) {
                continue;
            }

            foreach ($languages as $language) {
                $value = $row[$language] ?? '';
                $edit = $row[$language . '_edit'] ?? '';

                if ($edit !== '' && $edit !== '0') {
                    $value = $edit;
                }

                if ($value === '' || $value === '0' || is_array($value)) {
                    continue;
                }

                $legacy[$language][$group][$key] = (string)$value;
            }
        }

        $names = array_keys($contexts);
        sort($names, SORT_STRING);
        $modules = [];

        foreach ($languages as $language) {
            $modules[$language] = array_fill_keys(array_merge(['common'], $names), []);

            foreach ($legacy[$language] as $group => $values) {
                foreach ($values as $key => $value) {
                    $targets = $assignments[$group][$key] ?? [];
                    $shared = $targets === [] || (
                        in_array('frontend', $targets, true) && in_array('administration', $targets, true)
                    );

                    if ($shared) {
                        $modules[$language]['common'][$group][$key] = $value;
                    }

                    foreach ($targets as $target) {
                        if ($shared && ($target === 'frontend' || $target === 'administration')) {
                            continue;
                        }

                        $modules[$language][$target][$group][$key] = $value;
                    }
                }
            }
        }

        return ['contexts' => $names, 'legacy' => $legacy, 'modules' => $modules];
    }
}
