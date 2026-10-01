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

    private const LAST_SUBMITTED_SEARCH = 'flexy.search.last_submitted';

    /**
     * The count is queried here rather than read off the listing below, which has not rendered
     * yet — one extra query, and the number is right without JavaScript.
     */
    public function mount(string $query = ''): void
    {
        $this->query = $query;
        $this->productCount = $this->isNewSubmission($query)
            ? $this->productSearch->countSubmitted($query)
            : $this->productSearch->count($query);
    }

    /**
     * A search the shopper submitted carries no `page` number: the pagination links always add
     * one, page 1 included, and re-sorting re-renders the listing alone. A reload or a step back
     * to the same results is not a new search either: the session remembers the last term.
     */
    private function isNewSubmission(string $query): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        if (null === $request) {
            return true;
        }

        if ($request->query->has('page')) {
            return false;
        }

        if (!$request->hasSession()) {
            return true;
        }

        $term = trim($query);
        $session = $request->getSession();

        if ($session->get(self::LAST_SUBMITTED_SEARCH) === $term) {
            return false;
        }

        $session->set(self::LAST_SUBMITTED_SEARCH, $term);

        return true;
    }
}
