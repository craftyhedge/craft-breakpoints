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
    public function testCaptureGroupsExtendsAndEmbedParentsOnTheirIncludeLevel(): void
    {
        $twig = $this->createTwig([
            '_config.twig' => '{% block body %}{% endblock %}',
            '_layout.twig' => "{% extends '_config.twig' %}{% block body %}<main>{% block content %}{% endblock %}</main>{% endblock %}",
            'page.twig' => "{% extends '_layout.twig' %}{% block content %}"
                . "{% include '_partials/nav.twig' %}|"
                . "{% embed '_partials/card.twig' %}{% block inner %}{{ stack() }}{% endblock %}{% endembed %}"
                . '{% endblock %}',
            '_partials/nav.twig' => "{% include '_partials/cta.twig' %}",
            '_partials/cta.twig' => '{{ stack() }}',
            '_partials/card.twig' => '{% block inner %}{% endblock %}',
        ]);

        [$included, $embedded] = explode('|', str_replace(['<main>', '</main>'], '', $twig->render('page.twig')));

        $this->assertSame([
            ['template' => 'page', 'extends' => ['_layout', '_config']],
            ['template' => '_partials/nav'],
            ['template' => '_partials/cta'],
        ], json_decode($included, true));
        $this->assertSame([
            ['template' => 'page', 'extends' => ['_layout', '_config'], 'embeds' => ['_partials/card']],
        ], json_decode($embedded, true));
    }

    public function testCaptureListsEmbedInParentLayoutOnThePageLevel(): void
    {
        $twig = $this->createTwig([
            '_layout.twig' => "{% embed '_card.twig' %}{% block inner %}{{ stack() }}{% endblock %}{% endembed %}",
            '_card.twig' => '{% block inner %}{% endblock %}',
            'page.twig' => "{% extends '_layout.twig' %}",
        ]);

        $this->assertSame(
            [['template' => 'page', 'extends' => ['_layout'], 'embeds' => ['_card'], 'in' => '_layout']],
            json_decode($twig->render('page.twig'), true),
        );
    }

    public function testCaptureNamesParentTemplateThatRenderedTheCall(): void
    {
        $twig = $this->createTwig([
            '_config.twig' => "{{ stack() }}|{% block body %}{% endblock %}",
            '_layout.twig' => "{% extends '_config.twig' %}{% block body %}{{ stack() }}{% endblock %}",
            'page.twig' => "{% extends '_layout.twig' %}",
        ]);

        [$inRoot, $inBlock] = explode('|', $twig->render('page.twig'));

        $this->assertSame(
            [['template' => 'page', 'extends' => ['_layout', '_config'], 'in' => '_config']],
            json_decode($inRoot, true),
        );
        $this->assertSame(
            [['template' => 'page', 'extends' => ['_layout', '_config'], 'in' => '_layout']],
            json_decode($inBlock, true),
        );
    }

    public function testCaptureFollowsDynamicExtends(): void
    {
        $twig = $this->createTwig([
            '_layout.twig' => '{% block content %}{% endblock %}',
            'page.twig' => "{% extends wide ? '_layout.twig' : '_missing.twig' %}{% block content %}{{ stack() }}{% endblock %}",
        ]);

        $this->assertSame(
            [['template' => 'page', 'extends' => ['_layout']]],
            json_decode($twig->render('page.twig', ['wide' => true]), true),
        );
    }

    public function testCaptureKeepsSelfIncludesAsSeparateLevels(): void
    {
        $twig = $this->createTwig([
            'tree.twig' => "{% if depth < 2 %}{% include 'tree.twig' with {depth: depth + 1} %}{% else %}{{ stack() }}{% endif %}",
        ]);

        $this->assertSame(
            [['template' => 'tree'], ['template' => 'tree'], ['template' => 'tree']],
            json_decode($twig->render('tree.twig', ['depth' => 0]), true),
        );
    }

    public function testCaptureKeepsMacroTemplateReachedAgainThroughIncludeAsSeparateLevels(): void
    {
        $twig = $this->createTwig([
            '_macros.twig' => "{% macro outer() %}{% include '_partials/figure.twig' %}{% endmacro %}"
                . '{% macro img() %}{{ stack() }}{% endmacro %}',
            '_partials/figure.twig' => "{% import '_macros.twig' as m %}{{ m.img() }}",
            'page.twig' => "{% import '_macros.twig' as m %}{{ m.outer() }}",
        ]);

        $this->assertSame(
            [
                ['template' => 'page'],
                ['template' => '_macros'],
                ['template' => '_partials/figure'],
                ['template' => '_macros'],
            ],
            json_decode($twig->render('page.twig'), true),
        );
    }

    public function testCaptureKeepsProjectTemplatesInBreakpointsFolder(): void
    {
        $twig = $this->createTwig([
            'page.twig' => "{% include 'breakpoints/hero-banner.twig' %}",
            'breakpoints/hero-banner.twig' => '{{ stack() }}',
        ]);

        $this->assertSame(
            [['template' => 'page'], ['template' => 'breakpoints/hero-banner']],
            json_decode($twig->render('page.twig'), true),
        );
    }

    public function testCaptureReturnsEmptyOutsideTwig(): void
    {
        $this->assertSame([], IncludeStack::capture());
    }

    public function testNormalizeAcceptsNamesAndLevelsAndStripsExtension(): void
    {
        $this->assertSame(
            [
                ['template' => 'page', 'extends' => ['_layout'], 'in' => '_layout'],
                ['template' => 'mega-menus'],
                ['template' => 'mega-menu-cta', 'embeds' => ['_card']],
            ],
            IncludeStack::normalize([
                ['template' => 'page.twig', 'extends' => ['_layout.twig', ''], 'in' => '_layout'],
                'mega-menus.twig',
                ['template' => 'mega-menu-cta', 'embeds' => ['_card'], 'in' => 'mega-menu-cta'],
                42,
                '',
                ['extends' => ['_orphan']],
            ]),
        );
    }

    public function testNormalizeDropsStringTemplates(): void
    {
        $this->assertSame(
            [['template' => 'page'], ['template' => 'breakpoints/hero-banner', 'extends' => ['_base']]],
            IncludeStack::normalize([
                '__string_template__abc123',
                'page',
                ['template' => 'breakpoints/hero-banner', 'extends' => ['__string_template__def456', '_base']],
            ]),
        );
    }

    public function testNormalizeCapsDepthKeepingLeafEndAndNameLength(): void
    {
        $names = array_map(static fn(int $index): string => 'frame-' . $index, range(1, IncludeStack::MAX_DEPTH + 4));
        $stack = IncludeStack::normalize($names);

        $this->assertCount(IncludeStack::MAX_DEPTH, $stack);
        $this->assertSame('frame-5', $stack[0]['template']);
        $this->assertSame('frame-' . (IncludeStack::MAX_DEPTH + 4), $stack[IncludeStack::MAX_DEPTH - 1]['template']);

        $long = IncludeStack::normalize([str_repeat('a', IncludeStack::MAX_NAME_LENGTH + 20)]);
        $this->assertSame(IncludeStack::MAX_NAME_LENGTH, mb_strlen($long[0]['template']));
    }

    public function testNormalizeRejectsNonArrays(): void
    {
        $this->assertSame([], IncludeStack::normalize('page'));
        $this->assertSame([], IncludeStack::normalize(null));
    }

    public function testFromValueAcceptsStackOrSingleName(): void
    {
        $this->assertSame(
            [['template' => '_layout'], ['template' => 'site/card']],
            IncludeStack::fromValue(['_layout.twig', ' site/card ']),
        );
        $this->assertSame([['template' => 'site/card']], IncludeStack::fromValue(' site/card.twig '));
        $this->assertSame(
            [['template' => 'page', 'extends' => ['_layout']], ['template' => 'site/card']],
            IncludeStack::fromValue(' [{"template":"page","extends":["_layout"]},{"template":"site/card"}] '),
        );
        $this->assertSame([], IncludeStack::fromValue('[not json'));
        $this->assertSame([], IncludeStack::fromValue('   '));
        $this->assertSame([], IncludeStack::fromValue(null));
    }

    public function testLeafCountAndDescribe(): void
    {
        $stack = [
            ['template' => 'page', 'extends' => ['_layout', '_config']],
            ['template' => 'images', 'embeds' => ['_component'], 'in' => 'images'],
        ];

        $this->assertSame('images', IncludeStack::leaf($stack));
        $this->assertSame('_layout', IncludeStack::leaf([['template' => 'page', 'extends' => ['_layout'], 'in' => '_layout']]));
        $this->assertNull(IncludeStack::leaf([]));
        $this->assertSame(5, IncludeStack::templateCount($stack));
        $this->assertSame('page (extends _layout › _config)', IncludeStack::describeLevel($stack[0]));
        $this->assertSame('page (extends _layout, in _layout)', IncludeStack::describeLevel(['template' => 'page', 'extends' => ['_layout'], 'in' => '_layout']));
        $this->assertSame('', IncludeStack::encode([]));
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
