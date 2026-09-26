<?php

/*
|--------------------------------------------------------------------------
| RTL Compliance Regression Guard
|--------------------------------------------------------------------------
|
| This project ships a layout-direction toggle (LTR English / RTL Arabic) that
| flips `dir` on the <html> element. The server-side suite cannot observe a
| direction regression, so this test performs a static pass over every Blade
| template and fails when a *physical*-direction Tailwind utility is used
| without an explicit `ltr:` / `rtl:` guard.
|
| Detection rules, and why each exists (false-positive avoidance):
|
| 1. Matching is anchored to the class-token shape, not a raw substring. The
|    file is tokenised on runs of `[A-Za-z0-9_\-\[\]./%#,*!():]`, and `:` is
|    kept inside the run so a full `dark:xl:ltr:ml-[90px]` chain stays one
|    token; the token is then split on `:` into its variants. This is what
|    keeps `rounded-lg` from matching `rounded-l`, and keeps the words
|    "left"/"right" in prose, CSS properties (`margin-left`, `left: 0`),
|    JS identifiers and comments from matching at all.
|
| 2. A token is only physical when its FINAL variant segment matches a banned
|    utility prefix. Every OTHER segment is treated as a variant, so
|    `ltr:xl:ml-[90px]` is allowed while `dark:hover:left-0` is still flagged
|    (a naive "preceded by a colon" check would miss the latter).
|
| 3. `-` is stripped from the front of the base segment so Tailwind's negative
|    modifier is not a bypass: `-mr-2` is a violation.
|
| 4. `border-l-` / `rounded-l-` require a trailing dash. Bare `border-left:`
|    in inline CSS or a `<style>` block therefore cannot match, and neither
|    can prose such as "border-left". Likewise `left-` / `right-` require a
|    trailing dash, so the CSS property `left: 0` (which tokenises to the
|    segment `left`) is not flagged.
|
| 5. `text-left` / `text-right` are matched as exact segments. `text-center`,
|    `text-start`, `text-end` and every other `text-*` utility are untouched.
|
| 6. Zero-value utilities: `ml-0`, `mr-0`, `pl-0`, `pr-0`, `border-l-0`,
|    `border-r-0`, `rounded-l-0`, `rounded-r-0` are ALLOWED, because `0` is
|    the initial value for margin, padding, border-width and border-radius —
|    applying it renders identically in both directions, so it cannot be a
|    direction regression. `left-0` / `right-0` are NOT covered by this
|    exemption: those are physical offsets, not neutral resets, so they are
|    reported like any other position utility.
|
| Known limitation, accepted deliberately: a class value cannot be
| distinguished from a hyphenated English word in prose, so a sentence holding
| a token shaped like `left-<word>` (e.g. "left-2 columns") would be reported.
| Tightening the value pattern to a known Tailwind scale was rejected because
| it would silently miss arbitrary values such as `right-[calc(100%-2rem)]`,
| and a missed regression is far worse than a noisy report. No such prose
| exists in the current templates.
|
*/

if (! function_exists('rtlBannedUtilityPrefixes')) {
    /**
     * Physical-direction utility prefixes, keyed by whether the prefix carries
     * its own trailing dash (all of them do except the two `text-*` utilities,
     * which are matched as exact segments).
     *
     * @return array<string, bool>
     */
    function rtlBannedUtilityPrefixes(): array
    {
        return [
            'ml-' => true,
            'mr-' => true,
            'pl-' => true,
            'pr-' => true,
            'left-' => true,
            'right-' => true,
            'border-l-' => true,
            'border-r-' => true,
            'rounded-l-' => true,
            'rounded-r-' => true,
            'text-left' => false,
            'text-right' => false,
        ];
    }
}

if (! function_exists('rtlZeroExemptPrefixes')) {
    /**
     * Physical prefixes whose value is allowed to be zero, because zero is the
     * initial value of the property and therefore direction-neutral.
     *
     * @return list<string>
     */
    function rtlZeroExemptPrefixes(): array
    {
        return ['ml-', 'mr-', 'pl-', 'pr-', 'border-l-', 'border-r-', 'rounded-l-', 'rounded-r-'];
    }
}

if (! function_exists('rtlTokenIsPhysicalUtility')) {
    /**
     * Determine whether a single tokenised candidate is a banned
     * physical-direction Tailwind utility.
     */
    function rtlTokenIsPhysicalUtility(string $token): bool
    {
        $variants = explode(':', $token);

        if (in_array('ltr', $variants, true) || in_array('rtl', $variants, true)) {
            return false;
        }

        $base = ltrim((string) end($variants), '-');

        foreach (rtlBannedUtilityPrefixes() as $prefix => $needsDash) {
            if ($needsDash) {
                if (! str_starts_with($base, $prefix)) {
                    continue;
                }

                $value = substr($base, strlen($prefix));
            } else {
                if ($base !== $prefix) {
                    continue;
                }

                $value = '';
            }

            if (in_array($value, ['0', '0px'], true) && in_array($prefix, rtlZeroExemptPrefixes(), true)) {
                continue;
            }

            return true;
        }

        return false;
    }
}

if (! function_exists('rtlViolationsInBladeSource')) {
    /**
     * Scan Blade source and return one violation record per offending token.
     *
     * @return list<array{line: int, class: string}>
     */
    function rtlViolationsInBladeSource(string $contents): array
    {
        $violations = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $index => $line) {
            preg_match_all('/[A-Za-z0-9_\-\[\]\.\/%#,*!():]+/', $line, $matches);

            foreach ($matches[0] as $token) {
                if (rtlTokenIsPhysicalUtility($token)) {
                    $violations[] = ['line' => $index + 1, 'class' => $token];
                }
            }
        }

        return $violations;
    }
}

it('does not use physical direction utilities in blade views', function () {
    $viewsPath = str_replace('\\', '/', base_path('resources/views'));

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($viewsPath, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $current): bool {
                if ($current->isDir()) {
                    // Never descend into published vendor views or any
                    // compiled/cached view output.
                    return ! in_array($current->getFilename(), ['vendor', 'cache'], true);
                }

                return true;
            }
        )
    );

    $files = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php' && str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    $violations = [];

    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file, strlen($viewsPath) + 1));

        foreach (rtlViolationsInBladeSource((string) file_get_contents($file)) as $violation) {
            $violations[] = "resources/views/{$relative}:{$violation['line']} -> \"{$violation['class']}\"";
        }
    }

    expect($files)->not->toBeEmpty();

    $message = $violations === []
        ? 'Expected zero physical-direction Tailwind utilities in resources/views.'
        : 'Physical-direction Tailwind utilities found ('.count($violations)."):\n"
            .implode("\n", array_map(
                fn (string $violation): string => '  - '.$violation
                    .' — replace it with a logical equivalent (ms-/me-, ps-/pe-, start-/end-, border-s-/border-e-, rounded-s-/rounded-e-, text-start/text-end)'
                    .' or scope it with an ltr:/rtl: variant.',
                $violations
            ));

    expect($violations)->toBe([], $message);
});

it('detects physical utilities without flagging their logical counterparts', function () {
    $unsafe = [
        'text-left',
        'dark:text-right',
        'right-0',
        'left-0',
        '-mr-2',
    ];

    foreach ($unsafe as $classes) {
        expect(rtlViolationsInBladeSource('<div class="'.$classes.'">'))->toHaveCount(1);
    }

    $safe = [
        'text-center',
        'text-start',
        'text-end',
        'rounded-lg',
        'rounded-s-xl',
        'rounded-e-xl',
        'ms-4 me-4',
        'ps-2 pe-2',
        'start-0 end-0',
        'border-s border-e',
        'border-l-0',
        'rounded-r-0',
        'ml-0',
        'ltr:ml-[90px] rtl:mr-[90px]',
        'ltr:ml-[90px]',
        'rtl:pr-4',
        'dark:hover:ltr:left-0',
        'max-xl:-translate-x-full max-xl:rtl:translate-x-full',
        'flex items-center justify-between',
        'ltr:border-r rtl:border-l',
    ];

    foreach ($safe as $classes) {
        expect(rtlViolationsInBladeSource('<div class="'.$classes.'">'))->toBeEmpty();
    }

    expect(rtlViolationsInBladeSource('<p style="margin-left: 4px; left: 0; border-left: 1px">left right</p>'))->toBeEmpty();
    expect(rtlViolationsInBladeSource('<span dir="rtl">مرحبا، مرحبا بكم left</span>'))->toBeEmpty();
    expect(rtlViolationsInBladeSource('<script>const offsetLeft = 1; // align to the left</script>'))->toBeEmpty();
    expect(rtlViolationsInBladeSource(':class="{ \'is-open\': isOpen, \'ml-4\': isOpen }"'))->toHaveCount(1);
    expect(rtlViolationsInBladeSource('<div class="ml-4 {{ $a ? \'mr-2\' : \'me-2\' }}">'))->toHaveCount(2);
});
