<?php

namespace App\Security;

use App\Service\MercureSubscriberCookieFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private MercureSubscriberCookieFactory $mercureCookieFactory
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): RedirectResponse
    {
        $roles = $token->getRoleNames();

        if (in_array('ROLE_ADMIN', $roles, true)) {
            $url = $this->urlGenerator->generate('admin');
        } elseif (in_array('ROLE_INTERLOCUTEUR', $roles, true)) {
            $url = $this->urlGenerator->generate('interlocuteur_dashboard');
        } else {
            $url = $this->urlGenerator->generate('user_dashboard');
        }

        $response = new RedirectResponse($url);

        // Autorise le navigateur à s'abonner au topic Mercure "tickets"
        // pour le rafraîchissement temps réel des dashboards.
        $response->headers->setCookie($this->mercureCookieFactory->build());

        return $response;
    }
}