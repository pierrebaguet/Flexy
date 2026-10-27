<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FlexyBundle\Tests\Component;

use FlexyBundle\Components\Molecules\CountrySuggestion\Base as CountrySuggestion;
use FlexyBundle\Components\Organisms\LangSelect\Base as LangSelect;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Propel\Runtime\Propel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Localization\VisitorCountry\VisitorCountrySession;
use Thelia\Domain\Localization\VisitorCountryFacade;
use Thelia\Model\ConfigQuery;
use Thelia\Model\CountryI18nQuery;
use Thelia\Model\CountryQuery;
use Thelia\Model\Lang;

/**
 * The offer of the detected country is only there while the shop has something to offer,
 * and never in the checkout. Nothing is written to the database: the settings are put in
 * the cache of the shop settings and the session is the one of the request pushed here.
 */
final class CountrySuggestionRenderTest extends KernelTestCase
{
    private Session $session;

    private RequestStack $requestStack;

    protected function setUp(): void
    {
        if (!class_exists(VisitorCountryFacade::class)) {
            self::markTestSkipped('The installed core does not detect the country of the visitor yet.');
        }

        $this->requestStack = self::getContainer()->get('request_stack');
        $this->session = new Session(new MockArraySessionStorage());
        $this->pushRequest('/sofas.html');

        self::getContainer()->get('twig')->addGlobal('lang_code', 'en');
    }

    protected function tearDown(): void
    {
        ConfigQuery::resetCache();

        parent::tearDown();
    }

    #[IgnoreDeprecations]
    public function testAnOfferedCountryIsNamedAndAnsweredByTwoForms(): void
    {
        $this->shopOffersBelgium();

        $html = $this->render();

        self::assertStringContainsString('CountrySuggestion', $html);
        self::assertStringContainsString('You seem to be in Belgium.', $html);
        self::assertSame(2, substr_count($html, '<form method="post"'));
        self::assertStringContainsString('name="country_id" value="'.$this->belgiumId().'"', $html);
        self::assertStringContainsString('name="redirect" value="/sofas.html"', $html);
    }

    /**
     * The button renders its text with the markup of a span allowed, so the name of the
     * country, which an administrator types, must reach it escaped.
     */
    #[IgnoreDeprecations]
    public function testTheNameOfTheCountryIsEscapedInTheTextOfTheButtons(): void
    {
        $this->shopOffersBelgium();
        $locale = Lang::getDefaultLanguage()->getLocale();
        $title = CountryI18nQuery::create()->filterById($this->belgiumId())->filterByLocale($locale)->findOne();
        self::assertNotNull($title);
        $original = $title->getTitle();

        try {
            $title->setTitle('<span onmouseover="x">BE</span>')->save();

            $html = $this->render();
        } finally {
            $title->setTitle($original)->save();
        }

        self::assertStringContainsString('See prices for', $html);
        self::assertStringNotContainsString('<span onmouseover="x">', $html);
        self::assertStringNotContainsString('onmouseover="x"', $html);
    }

    #[IgnoreDeprecations]
    public function testWithoutAnythingToOfferNothingIsRendered(): void
    {
        $this->setting('suggest');

        self::assertSame('', trim($this->render()));
    }

    #[IgnoreDeprecations]
    public function testOnceTheVisitorAnsweredNothingIsRendered(): void
    {
        $this->shopOffersBelgium();
        $this->session->set(VisitorCountrySession::ANSWERED_KEY, true);

        self::assertSame('', trim($this->render()));
    }

    #[IgnoreDeprecations]
    public function testNothingIsRenderedInTheCheckout(): void
    {
        $this->shopOffersBelgium();
        $this->requestStack->getMainRequest()?->attributes->set('_route', 'checkout_cart');

        self::assertSame('', trim($this->render()));
    }

    #[IgnoreDeprecations]
    public function testWithoutTheFacadeOfTheCoreNothingIsRendered(): void
    {
        $component = new CountrySuggestion($this->requestStack, $this->session, self::getContainer()->get(LangSelect::class), null);

        self::assertFalse($component->isOffered());
        self::assertSame('', $component->getCountryName());
        self::assertNull($component->getSwitchUrl());
    }

    /**
     * The country is an instance of the Propel pool, shared with the rest of the request:
     * naming it must not leave it in the language that was read.
     */
    public function testNamingTheCountryLeavesTheSharedInstanceInItsLocale(): void
    {
        $this->shopOffersBelgium();
        Propel::enableInstancePooling();

        try {
            $shared = CountryQuery::create()->findPk($this->belgiumId());
            $shared->setLocale('fr_FR');
            $this->session->setLang((new Lang())->setLocale('en_US'));

            self::assertSame('Belgium', $this->component()->getCountryName());
            self::assertSame('fr_FR', $shared->getLocale());
        } finally {
            Propel::disableInstancePooling();
        }
    }

    public function testALanguageWithoutATitleFallsBackToTheDefaultLanguage(): void
    {
        $this->shopOffersBelgium();
        $this->session->setLang((new Lang())->setLocale('xx_XX'));

        $expected = CountryI18nQuery::create()->filterById($this->belgiumId())->filterByLocale(Lang::getDefaultLanguage()->getLocale())->findOne()?->getTitle();

        self::assertNotEmpty($expected, 'The test database has a title of Belgium in the default language.');
        self::assertSame($expected, $this->component()->getCountryName());
    }

    /**
     * The link is the one of the language selector for the page being read, in the language
     * of the detected country.
     */
    public function testWithOneDomainPerLanguageAcceptingIsAVisitToTheLanguageOfTheCountry(): void
    {
        $this->shopOffersBelgium();
        ConfigQuery::initCache(['visitor_country_mode' => 'suggest', 'visitor_country_source' => 'header', 'one_domain_foreach_lang' => '1']);
        $dutchBelgium = (new Lang())->setLocale('nl_BE')->setCode('nb')->setTitle('Nederlands (BE)')->setUrl('https://be.example.test')->setActive(1)->setVisible(1)->setPosition(99);
        $dutchBelgium->save();

        try {
            $this->session->setLang((new Lang())->setLocale('en_US'));
            $url = $this->component()->getSwitchUrl();

            self::assertSame('/sofas.html?lang=nl_BE', $url);
        } finally {
            $dutchBelgium->delete();
        }
    }

    private function component(): CountrySuggestion
    {
        return new CountrySuggestion($this->requestStack, $this->session, self::getContainer()->get(LangSelect::class), self::getContainer()->get(VisitorCountryFacade::class));
    }

    private function shopOffersBelgium(): void
    {
        $this->setting('suggest');
        $this->session->set(VisitorCountrySession::DETECTED_KEY, $this->belgiumId());
    }

    /**
     * The shop settings are read from a cache the kernel fills from the database: it is
     * replaced here, so the database is left as the test found it.
     */
    private function setting(string $mode): void
    {
        ConfigQuery::initCache(['visitor_country_mode' => $mode, 'visitor_country_source' => 'header']);
    }

    private function belgiumId(): int
    {
        $belgium = CountryQuery::create()->findOneByIsoalpha2('BE');
        self::assertNotNull($belgium, 'The test database has no Belgium.');
        self::assertSame(1, $belgium->getVisible(), 'Belgium must be listed for the offer.');

        return $belgium->getId();
    }

    private function pushRequest(string $uri): void
    {
        $request = Request::create('https://localhost'.$uri);
        $request->setSession($this->session);
        $this->requestStack->push($request);
    }

    private function render(): string
    {
        /** @var ComponentRendererInterface $renderer */
        $renderer = self::getContainer()->get('ux.twig_component.component_renderer');

        return $renderer->createAndRender('Molecules:CountrySuggestion:Base', []);
    }
}
