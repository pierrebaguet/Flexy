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

namespace FlexyBundle\Components\Layouts\Subheader;

use FlexyBundle\Service\ProductSearch;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
class Search
{
    public string $query = '';
    public int $productCount = 0;

    public function __construct(
        private readonly ProductSearch $productSearch,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * The count is queried here rather than read off the listing below, which has not rendered
     * yet — one extra query, and the number is right without JavaScript.
     *
     * The first page is the submitted search: the pagination links reload the page with a
     * `page` number, and re-sorting re-renders the listing alone, so neither counts again.
     */
    public function mount(string $query = ''): void
    {
        $this->query = $query;
        $this->productCount = $this->isFirstPage()
            ? $this->productSearch->countSubmitted($query)
            : $this->productSearch->count($query);
    }

    private function isFirstPage(): bool
    {
        return (int) ($this->requestStack->getCurrentRequest()?->query->get('page') ?? 1) <= 1;
    }
}
