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

namespace FlexyBundle\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Domain\Localization\VisitorCountryFacade;
use Thelia\Tools\RedirectUrl;

/**
 * The answer of the visitor to the offer of the country the shop detected.
 *
 * Both actions change what the session remembers, so they are POST-only and carry the
 * stateless CSRF token of the banner (`thelia_country_suggestion`, declared in
 * config/packages/csrf.yaml): it checks the origin of the request, not a session.
 */
#[Route('/country-suggestion', name: 'country_suggestion_')]
class CountrySuggestionController extends FlexyController
{
    private const string TOKEN_ID = 'thelia_country_suggestion';

    #[Route('/accept', name: 'accept', methods: ['POST'])]
    public function accept(Request $request, CsrfTokenManagerInterface $csrfTokenManager, ?VisitorCountryFacade $visitorCountry = null): RedirectResponse
    {
        $facade = $this->guard($visitorCountry);
        $this->checkToken($csrfTokenManager, $request);

        // The facade checks that the shop shows the country: a forged id changes nothing.
        $facade->accept((int) $request->request->get('country_id'));

        return $this->backToThePage($request);
    }

    #[Route('/decline', name: 'decline', methods: ['POST'])]
    public function decline(Request $request, CsrfTokenManagerInterface $csrfTokenManager, ?VisitorCountryFacade $visitorCountry = null): RedirectResponse
    {
        $facade = $this->guard($visitorCountry);
        $this->checkToken($csrfTokenManager, $request);

        $facade->decline();

        return $this->backToThePage($request);
    }

    /**
     * A core that predates the detection has nothing to answer to.
     */
    private function guard(?VisitorCountryFacade $visitorCountry): VisitorCountryFacade
    {
        if (!class_exists(VisitorCountryFacade::class) || null === $visitorCountry) {
            throw new NotFoundHttpException();
        }

        return $visitorCountry;
    }

    private function checkToken(CsrfTokenManagerInterface $csrfTokenManager, Request $request): void
    {
        if (!$csrfTokenManager->isTokenValid(new CsrfToken(self::TOKEN_ID, (string) $request->request->get('_token')))) {
            throw new AccessDeniedHttpException();
        }
    }

    private function backToThePage(Request $request): RedirectResponse
    {
        $url = (string) $request->request->get('redirect');

        if (!RedirectUrl::isSafe($url, $request->getHost())) {
            $url = $request->getBaseUrl().'/';
        }

        return $this->generateRedirect($url, 303);
    }
}
