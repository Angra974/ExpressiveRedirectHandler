<?php

/**
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ExpressiveRedirectHandler\Middleware;

use InvalidArgumentException;
use Pdp\Parser;
use Pdp\PublicSuffixListManager;
use Zend\Diactoros\Response\RedirectResponse;
use Zend\Expressive\Router\RouterInterface;
use Zend\Diactoros\Uri;

class RedirectHandlerAction
{
    private $config;

    private $router;

    public function __construct(array $config, RouterInterface $router)
    {
        $this->config = $config;
        $this->router = $router;
    }

    public function __invoke($request, $response, callable $next = null)
    {
        if (null === $next) {
            return $response;
        }

        $response = $next($request, $response);

        if (isset($this->config['header_handler']['enable'])
            && $this->config['header_handler']['enable'] === true
            && ! $response instanceof RedirectResponse
            && ! empty($this->config['header_handler']['headers'])
        ) {
            $statusCode = $response->getStatusCode();
            foreach ($this->config['header_handler']['headers'] as $code => $redirect) {
                if (! \is_string($redirect)) {
                    throw new InvalidArgumentException(\sprintf(
                        'redirect value for %s must be a string',
                        $code
                    ));
                }
                if ($code === $statusCode) {
                    $response = new RedirectResponse($redirect);
                }
            }
        }

        if ($response instanceof RedirectResponse) {
            $allow_not_routed_url = (isset($this->config['allow_not_routed_url'])) ? $this->config['allow_not_routed_url'] : false;
            $exclude_urls = (isset($this->config['options']['exclude_urls'])) ? $this->config['options']['exclude_urls'] : [];
            $exclude_hosts = (isset($this->config['options']['exclude_hosts'])) ? $this->config['options']['exclude_hosts'] : [];
            $exclude_domains = (isset($this->config['options']['exclude_domains'])) ? $this->config['options']['exclude_domains'] : [];

            $uriTarget     = $response->getHeader('location')[0];
            $uriTargetHost = (new Uri($uriTarget))->getHost();

            $redirectOfDomain = false;
            foreach ($exclude_domains as $key => $domain) {

                if ($key === 0) {
                    $pslManager = new PublicSuffixListManager();
                    $parser     = new Parser($pslManager->getList());
                }

                if (! $parser->isSuffixValid($domain)) {
                    throw new InvalidArgumentException(\sprintf(
                        '%s is not a valid domain',
                        $domain
                    ));
                }

                if ($parser->parseUrl($uriTarget)->host->registerableDomain === $domain) {
                    $redirectOfDomain = true;
                }

            }

            if (true === $allow_not_routed_url ||
                \in_array($uriTarget, $exclude_urls) ||
                \in_array($uriTargetHost, $exclude_hosts) ||
                $redirectOfDomain
            ) {
                return $response;
            }

            $default_url = (isset($this->config['default_url'])) ? $this->config['default_url'] : '/';
            $currentPath = $request->getUri()->getPath();

            $newUri        = new Uri($uriTarget);
            $request       = $request->withUri($newUri);
            $uriTargetPath = $newUri->getPath();
            $match         = $this->router->match($request);

            if ($currentPath === $default_url
                || $uriTarget === $default_url
                || $uriTargetPath === $default_url
            ) {
                return;
            }

            if ($match->isFailure()
                || ($match->isSuccess()
                    && $uriTargetPath === $currentPath
                    && $uriTarget !== $default_url
                    && $uriTargetPath !== $default_url
                )
            ) {
                return $response->withHeader('location', $default_url);
            }
        }

        return $response;
    }
}
