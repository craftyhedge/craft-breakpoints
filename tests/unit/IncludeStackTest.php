<?php

declare(strict_types=1);

namespace craftyhedge\craftbreakpoints\tests\unit;

use Codeception\Test\Unit;
use craftyhedge\craftbreakpoints\helpers\IncludeStack;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFunction;

final class IncludeStackTest extends Unit
{
    public function testCaptureReturnsRootToLeafStackThroughIncludeEmbedAndExtends(): void
    {
        $twig = $this->createTwig([
            '_layout.twig' => '{% block content %}{% endblock %}',
            'page.twig' => "{% extends '_layout.twig' %}{% block content %}"
                . "{% include '_partials/nav.twig' %}|"
                . "{% embed '_partials/card.twig' %}{% block body %}{{ stack() }}{% endblock %}{% endembed %}"
                . '{% endblock %}',
            '_partials/nav.twig' => "{% include '_partials/cta.twig' %}",
            '_partials/cta.twig' => '{{ stack() }}',
            '_partials/card.twig' => '{% block body %}{% endblock %}',
        ]);

        [$included, $embedded] = explode('|', $twig->render('page.twig'));

        $this->assertSame(['page', '_layout', 'page', '_partials/nav', '_partials/cta'], json_decode($included, true));
        $this->assertSame(['page', '_layout', 'page', '_partials/card', 'page'], json_decode($embedded, true));
    }

    public function testCaptureKeepsProjectTemplatesInBreakpointsFolder(): void
    {
        $twig = $this->createTwig([
            'page.twig' => "{% include 'breakpoints/hero-banner.twig' %}",
            'breakpoints/hero-banner.twig' => '{{ stack() }}',
        ]);

        $this->assertSame(['page', 'breakpoints/hero-banner'], json_decode($twig->render('page.twig'), true));
    }

    public function testCaptureReturnsEmptyOutsideTwig(): void
    {
        $this->assertSame([], IncludeStack::capture());
    }

    public function testNormalizeCollapsesConsecutiveDuplicatesAndStripsExtension(): void
    {
        $this->assertSame(
            ['_layout', 'mega-menus', 'mega-menu-cta'],
            IncludeStack::normalize(['_layout.twig', '_layout', 'mega-menus', 'mega-menus.twig', 'mega-menu-cta', 42, '']),
        );
    }

    public function testNormalizeDropsStringTemplates(): void
    {
        $this->assertSame(
            ['page', 'breakpoints/hero-banner'],
            IncludeStack::normalize(['__string_template__abc123', 'page', 'breakpoints/hero-banner']),
        );
    }

    public function testNormalizeCapsDepthKeepingLeafEndAndNameLength(): void
    {
        $names = array_map(static fn(int $index): string => 'frame-' . $index, range(1, IncludeStack::MAX_DEPTH + 4));
        $stack = IncludeStack::normalize($names);

        $this->assertCount(IncludeStack::MAX_DEPTH, $stack);
        $this->assertSame('frame-5', $stack[0]);
        $this->assertSame('frame-' . (IncludeStack::MAX_DEPTH + 4), $stack[IncludeStack::MAX_DEPTH - 1]);

        $long = IncludeStack::normalize([str_repeat('a', IncludeStack::MAX_NAME_LENGTH + 20)]);
        $this->assertSame(IncludeStack::MAX_NAME_LENGTH, mb_strlen($long[0]));
    }

    public function testNormalizeRejectsNonArrays(): void
    {
        $this->assertSame([], IncludeStack::normalize('page'));
        $this->assertSame([], IncludeStack::normalize(null));
    }

    /**
     * @param array<string, string> $templates
     */
    private function createTwig(array $templates): Environment
    {
        $twig = new Environment(new ArrayLoader($templates), ['autoescape' => false]);
        $twig->addFunction(new TwigFunction(
            'stack',
            static fn(): string => (string)json_encode(IncludeStack::capture(), JSON_UNESCAPED_SLASHES),
        ));

        return $twig;
    }
}
