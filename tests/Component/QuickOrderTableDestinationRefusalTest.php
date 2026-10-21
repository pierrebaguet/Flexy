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

use FlexyBundle\Components\Organisms\QuickOrderTable\Base as QuickOrderTable;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\ComponentAttributes;
use Symfony\UX\TwigComponent\ComponentFactory;
use Symfony\UX\TwigComponent\ComponentRendererInterface;
use Symfony\UX\TwigComponent\MountedComponent;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\QuickOrder\DTO\QuickOrderLine;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionChecker;
use Thelia\Domain\Shipping\DestinationRestriction\DestinationRestrictionFacade;
use Thelia\Model\CartItemQuery;
use Thelia\Model\ProductSaleElements;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;
use Twig\Environment;
use Twig\Runtime\EscaperRuntime;

/**
 * A quick order holding a product the destination restrictions keep from the address of the buyer adds nothing:
 * every row stays, the refused one says why, and the buyer removes it to add the others.
 *
 * Boots the host kernel on the test database `bin/test-prepare` creates.
 */
final class QuickOrderTableDestinationRefusalTest extends IntegrationTestCase
{
    private const string NAME = 'Organisms:QuickOrderTable:Base';

    protected function setUp(): void
    {
        parent::setUp();

        if (!method_exists(QuickOrderLine::class, 'refusedByTheDestination')) {
            self::markTestSkipped('The core published does not judge the destination of a quick order yet.');
        }
    }

    public function testALineTheAddressRefusesKeepsEveryRowAndAddsNothing(): void
    {
        $factory = $this->createFixtureFactory();
        $country = $factory->country(['isocode' => '9'.random_int(10, 99), 'isoalpha2' => 'X'.\chr(random_int(65, 90)), 'isoalpha3' => 'X'.\chr(random_int(65, 90)).\chr(random_int(65, 90))]);
        $customer = $factory->customer($factory->customerTitle());
        $factory->address($customer, $country)->setIsDefault(1)->save($this->getPropelConnection());
        $served = $this->saleElements($factory, 'Glass');
        $refused = $this->saleElements($factory, 'Calvados');

        $restrictions = $this->getService(DestinationRestrictionFacade::class);
        $group = $restrictions->save(null, 'test-'.bin2hex(random_bytes(4)), 0, true, ['en_US' => ['title' => 'Alcohol', 'reason' => 'Alcohol licence']]);
        $restrictions->addDestination($group->getId(), $country->getId());
        $restrictions->attachProduct($group->getId(), $refused->getProductId());
        $this->getService(DestinationRestrictionChecker::class)->reset();

        $session = $this->getService(RequestStack::class)->getMainRequest()?->getSession();
        self::assertInstanceOf(Session::class, $session);
        $session->setCustomerUser($customer);
        $cart = $factory->cart($customer);
        $session->setSessionCart($cart);

        $table = $this->table();
        $table->rows = [
            ['key' => 'a', 'reference' => (string) $served->getRef(), 'quantity' => 2, 'productSaleElementsId' => null],
            ['key' => 'b', 'reference' => (string) $refused->getRef(), 'quantity' => 1, 'productSaleElementsId' => null],
        ];
        $table->check();
        self::assertSame(2, $table->readyCount(), 'both rows are ready before they are added');
        $checkedRows = $table->rows;

        $table->addToCart();

        self::assertSame('destination_refused', $table->error);
        self::assertSame($checkedRows, $table->rows, 'every row stays');
        self::assertTrue($table->isReady(0), 'the row the address serves can be added again');
        self::assertSame('destination_refused', $table->lineOf(1)['status'] ?? null);
        self::assertSame(0, CartItemQuery::create()->filterByCartId($cart->getId())->count(), 'nothing reached the cart');

        $crawler = new Crawler($this->render($table));
        self::assertSame('Alcohol licence', trim($crawler->filter('[data-testid="quick-order-destination-reason"]')->text()));
        self::assertStringContainsString('Not available for your address', $crawler->text());
        self::assertStringContainsString('Nothing was added to your cart: a product cannot be delivered to your address.', $crawler->text());
    }

    private function saleElements(FixtureFactory $factory, string $title): ProductSaleElements
    {
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency(), ['title' => $title, 'baseQuantity' => 10]);

        return $product->getProductSaleElementss()->getFirst()
            ?? throw new \LogicException('The product has no sale element.');
    }

    private function table(): QuickOrderTable
    {
        $factory = $this->getService('ux.twig_component.component_factory');
        self::assertInstanceOf(ComponentFactory::class, $factory);
        $table = $factory->get(self::NAME);
        self::assertInstanceOf(QuickOrderTable::class, $table);

        return $table;
    }

    private function render(QuickOrderTable $table): string
    {
        $twig = $this->getService('twig');
        self::assertInstanceOf(Environment::class, $twig);
        // Part of the context the Thelia parser hands a page template, which a component rendered alone does not get.
        $twig->addGlobal('lang_code', 'en');
        $renderer = $this->getService('ux.twig_component.component_renderer');
        self::assertInstanceOf(ComponentRendererInterface::class, $renderer);

        return $renderer->render(new MountedComponent(self::NAME, $table, new ComponentAttributes(['id' => 'quick-order'], $twig->getRuntime(EscaperRuntime::class))));
    }
}
