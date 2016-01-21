<?php
/**
 * @author @ct-jensschulze <jens.schulze@commercetools.de>
 */

namespace Commercetools\Sunrise\AppBundle\Controller;


use Commercetools\Core\Cache\CacheAdapterInterface;
use Commercetools\Core\Client;
use Commercetools\Core\Model\Category\Category;
use Commercetools\Sunrise\AppBundle\Model\Config;
use Commercetools\Sunrise\AppBundle\Repository\CategoryRepository;
use Commercetools\Sunrise\AppBundle\Repository\ProductTypeRepository;
use Commercetools\Sunrise\AppBundle\Model\View\ViewLink;
use Commercetools\Sunrise\AppBundle\Model\ViewDataCollection;
use Commercetools\Sunrise\AppBundle\Model\View\Header;
use Commercetools\Sunrise\AppBundle\Model\View\Tree;
use Commercetools\Sunrise\AppBundle\Model\View\Url;
use Commercetools\Sunrise\AppBundle\Model\ViewData;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Translation\TranslatorInterface;

class SunriseController
{
    const ITEMS_PER_PAGE = 12;
    const PAGE_SELECTOR_RANGE = 2;
    const FIRST_PAGE = 1;
    const ITEM_COUNT_ELEMENT = 'items';
    const SORT_ELEMENT = 'sort';
    const SORT_DEFAULT = 'new';

    const CSRF_TOKEN_FORM = 'csrfToken';

    const CACHE_TTL = 3600;

    /**
     * @var EngineInterface
     */
    protected $templating;

    /**
     * @var UrlGenerator
     */
    protected $generator;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var Config
     */
    protected $config;

    /**
     * @var CacheAdapterInterface
     */
    protected $cache;

    /**
     * @var Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $locale;

    protected $pagination;

    /**
     * @var CategoryRepository
     */
    protected $categoryRepository;

    /**
     * @var ProductTypeRepository
     */
    protected $productTypeRepository;

    /**
     * @var string
     */
    private $interpolationPrefix;

    /**
     * @var string
     */
    private $interpolationSuffix;

    /**
     * @var string
     */
    private $defaultNamespace;

    /**
     * @var Session
     */
    protected $session;

    /**
     * @var AuthorizationCheckerInterface
     */
    protected $authChecker;

    public function __construct(
        Client $client,
        $locale,
        UrlGenerator $generator,
        CacheAdapterInterface $cache,
        TranslatorInterface $translator,
        EngineInterface $templateEngine,
        AuthorizationCheckerInterface $authChecker,
        $config,
        Session $session,
        CategoryRepository $categoryRepository,
        ProductTypeRepository $productTypeRepository
    )
    {
        if (is_array($config)) {
            $config = new Config($config);
        }
        $this->authChecker = $authChecker;
        $this->session = $session;
        $this->locale = $locale;
        $this->generator = $generator;
        $this->templating = $templateEngine;
        $this->translator = $translator;
        $this->config = $config;
        $this->cache = $cache;
        $this->client = $client;
        $this->categoryRepository = $categoryRepository;
        $this->productTypeRepository = $productTypeRepository;

        $this->defaultNamespace = $this->config['i18n.namespace.defaultNs'];
        $this->interpolationPrefix = $this->config['i18n.interpolationPrefix'];
        $this->interpolationSuffix = $this->config['i18n.interpolationSuffix'];
    }

    protected function getViewData($title)
    {
        $viewData = new ViewData();
        $viewData->header = $this->getHeaderViewData($title);
        $viewData->meta = $this->getMetaData();
        $viewData->footer = $this->getFooterData();
        $viewData->seo = $this->getSeoData();
        $viewData->content = new ViewData();
        return $viewData;
    }

    protected function getHeaderViewData($title)
    {
        $header = new Header($title);
        $header->stores = new Url($this->trans('header.stores'), '');
        $header->help = new Url($this->trans('header.help'), '');
        $header->callUs = new Url(
            $this->trans('header.callUs', ['phone' => $this->config['sunrise.header.callUs']]),
            ''
        );
        $header->location = new ViewData();
        $languages = new ViewDataCollection();
        foreach ($this->config['languages'] as $language) {
            $languageEntry = new ViewData();
            $languageEntry->text = $this->trans('header.languages.' . $language);
            $languageEntry->value = $language;
            $languages->add($languageEntry);
        }
        $header->location->language = $languages;

        $countries = new ViewDataCollection();
        foreach ($this->config['countries'] as $country) {
            $countryEntry = new ViewData();
            $countryEntry->text = $this->trans('header.countries.' . $country);
            $countryEntry->value = $country;
            $countries->add($countryEntry);
        }

        $header->location->country = $countries;
        $header->user = new ViewData();
        $header->user->isLoggedIn = false;
        $header->user->signIn = new Url('Login', '');
        $header->miniCart = new ViewData();
        $header->miniCart->totalItems = $this->session->get('cartNumItems', 0);
        $header->navMenu = $this->getNavMenu();

        return $header;
    }

    protected function getNavMenu()
    {
        $navMenu = new ViewData();

        $cacheKey = 'category-menu-' . $this->locale;
        if ($this->cache->has($cacheKey)) {
            $categoryMenu = unserialize($this->cache->fetch($cacheKey));
        } else {
            $categories = $this->categoryRepository->getCategories();
            $categoryMenu = new ViewDataCollection();
            $roots = $this->sortCategoriesByOrderHint($categories->getRoots());

            foreach ($roots as $root) {
                /**
                 * @var Category $root
                 */
                $menuEntry = new Tree(
                    (string)$root->getName(), $this->getLinkFor('category', ['category' => $root->getSlug()])
                );
                if ($root->getSlug() == $this->config['sunrise.sale.slug']) {
                    $menuEntry->sale = true;
                }

                $subCategories = $this->sortCategoriesByOrderHint($categories->getByParent($root->getId()));
                foreach ($subCategories as $children) {
                    /**
                     * @var Category $children
                     */
                    $childrenEntry = new Tree(
                        (string)$children->getName(),
                        $this->getLinkFor('category', ['category' => $children->getSlug()])
                    );

                    $subChildCategories = $this->sortCategoriesByOrderHint($categories->getByParent($children->getId()));
                    foreach ($subChildCategories as $subChild) {
                        /**
                         * @var Category $subChild
                         */
                        $childrenSubEntry = new Url(
                            (string)$subChild->getName(),
                            $this->getLinkFor('category', ['category' => $subChild->getSlug()])
                        );
                        $childrenEntry->addNode($childrenSubEntry);
                    }
                    $menuEntry->addNode($childrenEntry);
                }
                $categoryMenu->add($menuEntry);
            }
            $categoryMenu = $categoryMenu->toArray();
            $this->cache->store($cacheKey, serialize($categoryMenu), static::CACHE_TTL);
        }
        $navMenu->categories = $categoryMenu;

        return $navMenu;
    }

    protected function getMetaData()
    {
        $meta = new ViewData();
        $meta->assetsPath = $this->config['sunrise.assetsPath'];
        $meta->_links = new ViewData();
        $meta->_links->home = new ViewLink($this->generator->generate('home'));
        $meta->_links->newProducts = new ViewLink($this->generator->generate('category', ['category' => 'new']));
        $meta->_links->addToCart = new ViewLink($this->generator->generate('cartAdd'));
        $meta->_links->cart = new ViewLink($this->generator->generate('cart'));
        $meta->csrfToken = $this->getCsrfToken(static::CSRF_TOKEN_FORM);
        $bagItems = new ViewDataCollection();
        for ($i = 1; $i < 10; $i++) {
            $bagItems->add($i);
        }
        $meta->bagQuantityOptions = $bagItems;


        return $meta;
    }

    protected function validateCsrfToken($formName, $token)
    {
        $storedToken = $this->session->get($formName);
        $this->session->remove($formName);
        if ($storedToken == $token) {
            return true;
        }

        return false;
    }

    protected function getCsrfToken($formName)
    {
        $token=hash("sha512",mt_rand(0,mt_getrandmax()));
        $this->session->set($formName, $token);

        return $token;
    }

    protected function getFooterData()
    {
        $footer = new ViewData();
        $footer->paySecure = $this->trans('footer.paySecure');
        $footer->followUs = $this->trans('footer.followUs');
        $footer->newsletter = new Url($this->trans('footer.newsletter.text'), '');
        $footer->newsletter->textAlt = $this->trans('footer.newsletter.textAlt');
        $footer->newsletter->placeHolder = $this->trans('footer.newsletter.placeHolder');
        $footer->newsletter->inputId = 'pop-newsletter-input';
        $footer->newsletter->buttonId = 'pop-newsletter-button';

        $footer->customerCare = new ViewData();
        $footer->customerCare->text = $this->trans('footer.customerCare.text');
        $ccList = new ViewDataCollection();
        $ccList->add(new Url($this->trans('footer.customerCare.contactUs'),''));
        $ccList->add(new Url($this->trans('footer.customerCare.help'),''));
        $ccList->add(new Url($this->trans('footer.customerCare.shipping'),''));
        $ccList->add(new Url($this->trans('footer.customerCare.returns'),''));
        $ccList->add(new Url($this->trans('footer.customerCare.sizeGuide'),''));
        $footer->customerCare->list = $ccList;

        $footer->aboutUs = new ViewData();
        $footer->aboutUs->text = $this->trans('footer.aboutUs.text');
        $aboutUsList = new ViewDataCollection();
        $aboutUsList->add(new Url($this->trans('footer.aboutUs.ourStory'),''));
        $aboutUsList->add(new Url($this->trans('footer.aboutUs.careers'),''));
        $footer->aboutUs->list = $aboutUsList;

        $footer->shortcuts = new ViewData();
        $footer->shortcuts->text = $this->trans('footer.shortcuts.text');
        $shortcutsList = new ViewDataCollection();
        $shortcutsList->add(new Url($this->trans('footer.shortcuts.myAccount'),''));
        $shortcutsList->add(new Url($this->trans('footer.shortcuts.stores'),''));
        $shortcutsList->add(new Url($this->trans('footer.shortcuts.giftCards'),''));
        $shortcutsList->add(new Url($this->trans('footer.shortcuts.payment'),''));
        $footer->shortcuts->list = $shortcutsList;

        $footer->legalInfo = new ViewData();
        $footer->legalInfo->text = $this->trans('footer.legalInfo.text');
        $legalInfoList = new ViewDataCollection();
        $legalInfoList->add(new Url($this->trans('footer.legalInfo.imprint'),''));
        $legalInfoList->add(new Url($this->trans('footer.legalInfo.privacy'),''));
        $legalInfoList->add(new Url($this->trans('footer.legalInfo.terms'),''));
        $footer->legalInfo->list = $legalInfoList;

        return $footer;
    }

    protected function getSeoData()
    {
        $seo = new ViewData();
        $seo->text = $this->trans('seo.text');
        $seo->description = $this->trans('seo.description');

        return $seo;
    }

    protected function trans($id, $parameters = [], $domain = null, $locale = null)
    {
        if (is_null($domain)) {
            $domain = $this->defaultNamespace;
        }
        return $this->translator->trans($id, $this->mapInterpolation($parameters), $domain, $locale);
    }

    protected function mapInterpolation($parameters)
    {
        $args = [];
        foreach ($parameters as $key => $value) {
            $key = $this->interpolationPrefix . $key . $this->interpolationSuffix;
            $args[$key] = $value;
        }
        return $args;
    }

    protected function getLinkFor($site, $params)
    {
        return $this->generator->generate($site, $params);
    }

    protected function applyPagination(UriInterface $uri, $offset, $total, $itemsPerPage)
    {
        $firstPage = static::FIRST_PAGE;
        $pageRange = static::PAGE_SELECTOR_RANGE;
        $currentPage = floor($offset / max(1, $itemsPerPage)) + 1;
        $totalPages = ceil($total / max(1, $itemsPerPage));

        $displayedPages = $pageRange * 2 + 3;
        $pageThresholdLeft = $displayedPages - $pageRange;
        $thresholdPageLeft = $displayedPages - 1;
        $pageThresholdRight = $totalPages - $pageRange - 2;
        $thresholdPageRight = $totalPages - $displayedPages + 2;
        $pagination = new ViewData();

        if ($totalPages <= $displayedPages) {
            $pagination->pages = $this->getPages($uri, $firstPage, $totalPages, $currentPage);
        } elseif ($currentPage < $pageThresholdLeft) {
            $pagination->pages = $this->getPages($uri, $firstPage, $thresholdPageLeft, $currentPage);
            $pagination->lastPage = $this->getPageUrl($uri, $totalPages);
        } elseif ($currentPage > $pageThresholdRight) {
            $pagination->pages = $this->getPages($uri, $thresholdPageRight, $totalPages, $currentPage);
            $pagination->firstPage = $this->getPageUrl($uri, $firstPage);
        } else {
            $pagination->pages = $this->getPages(
                $uri,
                $currentPage - $pageRange,
                $currentPage + $pageRange,
                $currentPage
            );
            $pagination->firstPage = $this->getPageUrl($uri, $firstPage);
            $pagination->lastPage = $this->getPageUrl($uri, $totalPages);
        }

        if ($currentPage > 1) {
            $prevPage = $currentPage - 1;
            $pagination->previousUrl = $this->getPageUrl($uri, $prevPage)->url;
        }
        if ($currentPage < $totalPages) {
            $nextPage = $currentPage + 1;
            $pagination->nextUrl = $this->getPageUrl($uri, $nextPage)->url;
        }

        $this->pagination = $pagination;
    }

    protected function getItemsPerPage(Request $request)
    {
        $itemsPerPage = $request->get(static::ITEM_COUNT_ELEMENT, static::ITEMS_PER_PAGE);
        if (!in_array($itemsPerPage, $this->config->get('sunrise.itemsPerPage'))) {
            return static::ITEMS_PER_PAGE;
        }
        return $itemsPerPage;
    }

    protected function getSort(Request $request, $configEntry)
    {
        $sort = $request->get(static::SORT_ELEMENT, static::SORT_DEFAULT);
        if (!array_key_exists($sort, $this->config->get($configEntry))) {
            return static::SORT_DEFAULT;
        }
        return $this->config->get($configEntry)[$sort];
    }

    protected function getCurrentPage(Request $request)
    {
        $currentPage = $request->get('page', 1);
        if ($currentPage < 1) {
            $currentPage = 1;
        }

        return $currentPage;
    }

    protected function getPageUrl(UriInterface $uri, $number, $query = 'page')
    {
        $url = new Url($number, Uri::withQueryValue($uri, $query,$number));
        return $url;
    }

    protected function getPages(UriInterface $uri, $start, $stop, $currentPage)
    {
        $pages = new ViewDataCollection();
        for ($i = $start; $i <= $stop; $i++) {
            $url = $this->getPageUrl($uri, $i);
            if ($currentPage == $i) {
                $url->selected = true;
            }
            $pages->add($url);
        }
        return $pages;
    }

    protected function sortCategoriesByOrderHint($categories)
    {
        usort($categories, function (Category $a, Category $b) {
            return $a->getOrderHint() > $b->getOrderHint();
        });

        return $categories;
    }

    protected function render($view, array $parameters = array())
    {
        return $this->templating->renderResponse($view, $parameters);
    }

    /**
     * Checks if the attributes are granted against the current authentication token and optionally supplied object.
     *
     * @param mixed $attributes The attributes
     * @param mixed $object     The object
     *
     * @return bool
     *
     * @throws \LogicException
     */
    protected function isGranted($attributes, $object = null)
    {
        return $this->authChecker->isGranted($attributes, $object);
    }

    /**
     * Throws an exception unless the attributes are granted against the current authentication token and optionally
     * supplied object.
     *
     * @param mixed  $attributes The attributes
     * @param mixed  $object     The object
     * @param string $message    The message passed to the exception
     *
     * @throws AccessDeniedException
     */
    protected function denyAccessUnlessGranted($attributes, $object = null, $message = 'Access Denied.')
    {
        if (!$this->isGranted($attributes, $object)) {
            throw $this->createAccessDeniedException($message);
        }
    }

    /**
     * Returns an AccessDeniedException.
     *
     * This will result in a 403 response code. Usage example:
     *
     *     throw $this->createAccessDeniedException('Unable to access this page!');
     *
     * @param string          $message  A message
     * @param \Exception|null $previous The previous exception
     *
     * @return AccessDeniedException
     */
    protected function createAccessDeniedException($message = 'Access Denied.', \Exception $previous = null)
    {
        return new AccessDeniedException($message, $previous);
    }
}
