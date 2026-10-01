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

namespace FlexyBundle\Service;

use FlexyBundle\DTO\ProductDTO;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Api\Service\DataAccess\DataAccessService;
use Thelia\Core\Event\Product\ProductSearchedEvent;
use Thelia\Domain\Localization\Service\LangService;

/**
 * Single entry point for product search, so swapping in a Thelia search module (TntSearch and the
 * like) means reimplementing this class rather than hunting down call sites.
 *
 * The API limits what it can do: `title` matches on word starts only ("Claire" misses
 * "Marie-Claire"), nothing but titles is searchable, and the i18n filter has no locale fallback —
 * a locale without translations returns nothing even though pages show fallback titles.
 */
final readonly class ProductSearch
{
    public function __construct(
        private DataAccessService $dataAccessService,
        private ProductSort $productSort,
        private EventDispatcherInterface $dispatcher,
        private LangService $langService,
    ) {
    }

    /**
     * A blank term matches nothing: the API would drop the filter and return the whole catalogue.
     *
     * @return array{products: list<ProductDTO>, total: int}
     */
    public function search(string $term, int $page = 1, int $itemsPerPage = 30, ?string $sort = null): array
    {
        if (trim($term) === '') {
            return ['products' => [], 'total' => 0];
        }

        $response = $this->dataAccessService->resources(
            '/api/front/products',
            $this->parameters($term, $page, $itemsPerPage, $sort),
            'jsonld',
        );

        return [
            'products' => ProductDTO::fromCollection($response['hydra:member'] ?? []),
            // JSON-LD decoding hands the total back as a float
            'total' => (int) ($response['hydra:totalItems'] ?? 0),
        ];
    }

    public function count(string $term): int
    {
        return $this->search($term, itemsPerPage: 1)['total'];
    }

    /**
     * The count of a search the shopper submitted, told to the modules once with
     * ProductSearchedEvent so a search log (TntSearch) records the visitors' searches. The
     * suggestions shown while typing go through search() and are not submitted searches.
     *
     * A core older than the event gets the count alone.
     */
    public function countSubmitted(string $term): int
    {
        $total = $this->count($term);

        if (trim($term) !== '' && class_exists(ProductSearchedEvent::class)) {
            $this->dispatcher->dispatch(new ProductSearchedEvent(trim($term), (string) $this->langService->getLocale(), $total));
        }

        return $total;
    }

    /**
     * @return array<string, mixed>
     */
    private function parameters(string $term, int $page, int $itemsPerPage, ?string $sort): array
    {
        $parameters = [
            'title' => trim($term),
            'visible' => true,
            'itemsPerPage' => $itemsPerPage,
            'page' => max(1, $page),
        ];

        return array_merge($parameters, $this->productSort->parameters($sort));
    }
}
