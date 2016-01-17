<?php
/**
 * OAuth 2.0 Refresh token grant
 *
 * @package     league/oauth2-server
 * @author      Alex Bilbie <hello@alexbilbie.com>
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 * @link        https://github.com/thephpleague/oauth2-server
 */

namespace League\OAuth2\Server\Grant;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use League\OAuth2\Server\Utils\KeyCrypt;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Refresh token grant
 */
class RefreshTokenGrant extends AbstractGrant
{
    /**
     * Grant identifier
     *
     * @var string
     */
    protected $identifier = 'refresh_token';

    /**
     * @var string
     */
    private $pathToPublicKey;

    /**
     * @var \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface
     */
    private $refreshTokenRepository;

    /**
     * @param string                                                             $pathToPublicKey
     * @param \League\OAuth2\Server\Repositories\ClientRepositoryInterface       $clientRepository
     * @param \League\OAuth2\Server\Repositories\ScopeRepositoryInterface        $scopeRepository
     * @param \League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface  $accessTokenRepository
     * @param \League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface $refreshTokenRepository
     */
    public function __construct(
        $pathToPublicKey,
        ClientRepositoryInterface $clientRepository,
        ScopeRepositoryInterface $scopeRepository,
        AccessTokenRepositoryInterface $accessTokenRepository,
        RefreshTokenRepositoryInterface $refreshTokenRepository
    ) {
        parent::__construct($clientRepository, $scopeRepository, $accessTokenRepository);

        $this->pathToPublicKey = $pathToPublicKey;
        $this->refreshTokenRepository = $refreshTokenRepository;
    }

    /**
     * @inheritdoc
     */
    public function respondToRequest(
        ServerRequestInterface $request,
        ResponseTypeInterface $responseType,
        \DateInterval $tokenTTL,
        $scopeDelimiter = ' '
    ) {
        $client = $this->validateClient($request);
        $oldRefreshToken = $this->validateOldRefreshToken($request, $client->getIdentifier());
        $scopes = $this->validateScopes($request, $scopeDelimiter, $client);

        // If no new scopes are requested then give the access token the original session scopes
        if (count($scopes) === 0) {
            $scopes = $oldRefreshToken['scopes'];
        } else {
            // The OAuth spec says that a refreshed access token can have the original scopes or fewer so ensure
            // the request doesn't include any new scopes
            foreach ($scopes as $scope) {
                if (in_array($scope->getIdentifier(), $oldRefreshToken['scopes']) === false) {
                    $this->emitter->emit(new Event('scope.selection.failed', $request));

                    throw OAuthServerException::invalidScope($scope->getIdentifier());
                }
            }
        }

        // Expire old tokens
        $this->accessTokenRepository->revokeAccessToken($oldRefreshToken['access_token_id']);
        $this->refreshTokenRepository->revokeRefreshToken($oldRefreshToken['refresh_token_id']);

        $accessToken = $this->issueAccessToken($tokenTTL, $client, $oldRefreshToken['user_id'], $scopes);
        $refreshToken = $this->issueRefreshToken($accessToken);

        $this->accessTokenRepository->persistNewAccessToken($accessToken);
        $this->refreshTokenRepository->persistNewRefreshToken($refreshToken);

        // Inject tokens into response
        $responseType->setAccessToken($accessToken);
        $responseType->setRefreshToken($refreshToken);

        return $responseType;
    }

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param string                                   $clientId
     *
     * @return array
     *
     * @throws \League\OAuth2\Server\Exception\OAuthServerException
     */
    protected function validateOldRefreshToken(ServerRequestInterface $request, $clientId)
    {
        $encryptedRefreshToken = $this->getRequestParameter('refresh_token', $request);
        if (is_null($encryptedRefreshToken)) {
            throw OAuthServerException::invalidRequest('refresh_token', null, '`%s` parameter is missing');
        }

        // Validate refresh token
        try {
            $refreshToken = KeyCrypt::decrypt($encryptedRefreshToken, $this->pathToPublicKey);
        } catch (\LogicException $e) {
            throw OAuthServerException::invalidRefreshToken('Cannot parse refresh token: ' . $e->getMessage());
        }

        $refreshTokenData = json_decode($refreshToken, true);
        if ($refreshTokenData['client_id'] !== $clientId) {
            $this->emitter->emit(new Event('refresh_token.client.failed', $request));

            throw OAuthServerException::invalidRefreshToken(
                'Token is not linked to client,' .
                ' got: ' . $clientId .
                ' expected: '. $refreshTokenData['client_id']
            );
        }

        if ($refreshTokenData['expire_time'] < time()) {
            throw OAuthServerException::invalidRefreshToken('Token has expired');
        }

        if ($this->refreshTokenRepository->isRefreshTokenRevoked($refreshTokenData['refresh_token_id']) === true) {
            throw OAuthServerException::invalidRefreshToken('Token has been revoked');
        }

        return $refreshTokenData;
    }
}
