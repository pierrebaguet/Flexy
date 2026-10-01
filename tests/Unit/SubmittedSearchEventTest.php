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

namespace FlexyBundle\Tests\Unit;

use FlexyBundle\Components\Layouts\Subheader\Search as SearchSubheader;
use FlexyBundle\Service\ProductSearch;
use FlexyBundle\Service\ProductSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Event\Product\ProductSearchedEvent;
use Thelia\Domain\Localization\Service\LangService;

/**
 * The search page tells the modules about the search a shopper submitted, once, with the
 * number of products found, so a search log counts the visitors' searches. The suggestions
 * shown while typing, the pages of the same results and a reload of them are not new searches.
 *
 * Skipped on a core that does not ship ProductSearchedEvent yet.
 */
final class SubmittedSearchEventTest extends TestCase
{
    /** @var list<ProductSearchedEvent> */
    private array $told = [];

    protected function setUp(): void
    {
        if (!class_exists(ProductSearchedEvent::class)) {
            self::markTestSkipped('The core does not ship ProductSearchedEvent.');
        }

        $this->told = [];
    }

    public function testTheSearchPageTellsTheSubmittedSearchWithItsCount(): void
    {
        $subheader = $this->subheader(new Request(['query' => '  chaussure ']), hits: 3);

        $subheader->mount('  chaussure ');

        self::assertSame(3, $subheader->productCount);
        self::assertCount(1, $this->told);
        self::assertSame('chaussure', $this->told[0]->getTerm());
        self::assertSame('fr_FR', $this->told[0]->getLocale());
        self::assertSame(3, $this->told[0]->getHits());
    }

    public function testASearchWithoutResultIsToldWithZeroHits(): void
    {
        $this->subheader(new Request(['query' => 'licorne']), hits: 0)->mount('licorne');

        self::assertCount(1, $this->told);
        self::assertSame(0, $this->told[0]->getHits());
    }

    public function testAFurtherPageOfTheSameResultsIsNotToldAgain(): void
    {
        $subheader = $this->subheader(new Request(['query' => 'chaussure', 'page' => '2']), hits: 40);

        $subheader->mount('chaussure');

        self::assertSame(40, $subheader->productCount);
        self::assertSame([], $this->told);
    }

    public function testBackToTheFirstPageOfTheSameResultsIsNotToldAgain(): void
    {
        $this->subheader(new Request(['query' => 'chaussure', 'page' => '1']), hits: 40)->mount('chaussure');

        self::assertSame([], $this->told);
    }

    public function testAReloadOfTheSameResultsIsNotToldAgain(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $this->subheader($this->withSession(new Request(['query' => 'chaussure']), $session), hits: 3)->mount('chaussure');
        $this->subheader($this->withSession(new Request(['query' => 'chaussure']), $session), hits: 3)->mount('chaussure');

        self::assertCount(1, $this->told);
    }

    public function testAnotherTermInTheSameSessionIsTold(): void
    {
        $session = new Session(new MockArraySessionStorage());

        $this->subheader($this->withSession(new Request(['query' => 'chaussure']), $session), hits: 3)->mount('chaussure');
        $this->subheader($this->withSession(new Request(['query' => 'botte']), $session), hits: 1)->mount('botte');
        $this->subheader($this->withSession(new Request(['query' => 'chaussure']), $session), hits: 3)->mount('chaussure');

        self::assertSame(['chaussure', 'botte', 'chaussure'], array_map(static fn (ProductSearchedEvent $event): string => $event->getTerm(), $this->told));
    }

    public function testABlankSearchIsNotTold(): void
    {
        $this->subheader(new Request(['query' => '   ']), hits: 0)->mount('   ');

        self::assertSame([], $this->told);
    }

    public function testTheSuggestionsShownWhileTypingAreNotTold(): void
    {
        $this->productSearch(hits: 2)->search('chau', itemsPerPage: 5);

        self::assertSame([], $this->told);
    }

    private function withSession(Request $request, Session $session): Request
    {
        $request->setSession($session);

        return $request;
    }

    private function subheader(Request $request, int $hits): SearchSubheader
    {
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new SearchSubheader($this->productSearch($hits), $requestStack);
    }

    private function productSearch(int $hits): ProductSearch
    {
        $dataAccess = self::createStub(DataAccessService::class);
        // JSON-LD decoding hands the total back as a float.
        $dataAccess->method('resources')->willReturn(['hydra:member' => [], 'hydra:totalItems' => (float) $hits]);

        $langService = self::createStub(LangService::class);
        $langService->method('getLocale')->willReturn('fr_FR');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ProductSearchedEvent::class, function (ProductSearchedEvent $event): void {
            $this->told[] = $event;
        });

        return new ProductSearch($dataAccess, new ProductSort(), $dispatcher, $langService);
    }
}
