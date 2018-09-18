<?php
  namespace Tagalys\Sync\Controller\Adminhtml\Configuration;

  class Edit extends \Magento\Backend\App\Action
  {

    protected function _isAllowed()
    {
     return $this->_authorization->isAllowed('Tagalys_Sync::tagalys_configuration');
    }
    
    /**
    * @var \Magento\Framework\View\Result\PageFactory
    */
    protected $resultPageFactory;

    /**
     * Constructor
     *
     * @param \Magento\Backend\App\Action\Context $context
     * @param \Magento\Framework\View\Result\PageFactory $resultPageFactory
     */
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        \Tagalys\Sync\Helper\Sync $tagalysSync,
        \Tagalys\Sync\Helper\Queue $queueHelper,
        \Tagalys\Mpages\Helper\Mpages $tagalysMpages
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        $this->tagalysSync = $tagalysSync;
        $this->messageManager = $context->getMessageManager();
        $this->queueHelper = $queueHelper;
        $this->tagalysMpages = $tagalysMpages;
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/tagalys.log');
        $this->logger = new \Zend\Log\Logger();
        $this->logger->addWriter($writer);
    }

    /**
     * Load the page defined in view/adminhtml/layout/exampleadminnewpage_helloworld_index.xml
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();

        $params = $this->getRequest()->getParams();
        if (!empty($params['tagalys_submit_action'])) {
            $result = false;
            $redirectToTab = null;
            switch ($params['tagalys_submit_action']) {
                case 'Save API Credentials':
                    try {
                        $result = $this->_saveApiCredentials($params);
                        if ($result !== false) {
                            $this->tagalysApi->log('info', 'Saved API credentials', array('api_credentials' => $params['api_credentials']));
                            $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
                            if ($setupStatus == 'api_credentials') {
                                $setupStatus = $this->tagalysConfiguration->setConfig('setup_status', 'sync_settings');
                            }
                        }
                        $redirectToTab = 'api_credentials';
                    } catch (\Exception $e) {
                        $this->tagalysApi->log('error', 'Error in _saveApiCredentials', array('api_credentials' => $params['api_credentials']));
                        $this->messageManager->addError("Sorry, something went wrong while saving your API credentials. Please <a href=\"mailto:cs@tagalys.com\">email us</a> so we can resolve this issue.");
                        $redirectToTab = 'api_credentials';
                    }
                    break;
                case 'Save & Continue to Sync':
                    try {
                        if (array_key_exists('search_box_selector', $params)) {
                            $this->tagalysConfiguration->setConfig('search_box_selector', $params['search_box_selector']);
                            $this->tagalysConfiguration->setConfig('suggestions_align_to_parent_selector', $params['suggestions_align_to_parent_selector']);
                        }
                        if (array_key_exists('periodic_full_sync', $params)) {
                            $this->tagalysConfiguration->setConfig('periodic_full_sync', $params['periodic_full_sync']);
                        }
                        if (array_key_exists('stores_for_tagalys', $params) && count($params['stores_for_tagalys']) > 0) {
                            $this->tagalysApi->log('info', 'Starting configuration sync', array('stores_for_tagalys' => $params['stores_for_tagalys']));
                            $result = $this->tagalysConfiguration->syncClientConfiguration($params['stores_for_tagalys']);
                            if ($result === false) {
                                $this->tagalysApi->log('error', 'syncClientConfiguration returned false', array('stores_for_tagalys' => $params['stores_for_tagalys']));
                                $this->messageManager->addError("Sorry, something went wrong while saving your store's configuration. We've logged the issue and we'll get back once we know more. You can contact us here: <a href=\"mailto:cs@tagalys.com\">cs@tagalys.com</a>");
                                $redirectToTab = 'sync_settings';
                            } else {
                                $this->tagalysApi->log('info', 'Completed configuration sync', array('stores_for_tagalys' => $params['stores_for_tagalys']));
                                $this->tagalysConfiguration->setConfig('stores', json_encode($params['stores_for_tagalys']));
                                foreach($params['stores_for_tagalys'] as $i => $storeId) {
                                    $this->tagalysSync->triggerFeedForStore($storeId);
                                }
                                $setupStatus = $this->tagalysConfiguration->getConfig('setup_status');
                                if ($setupStatus == 'sync_settings') {
                                    $this->tagalysConfiguration->setConfig('setup_status', 'sync');
                                }
                                $redirectToTab = 'sync';
                            }
                        } else {
                            $this->messageManager->addError("Please choose at least one store to continue.");
                            $redirectToTab = 'sync_settings';
                        }
                    } catch (\Exception $e) {
                        $this->tagalysApi->log('error', 'Error in syncClientConfiguration: ' . $e->getMessage(), array('stores_for_tagalys' => $params['stores_for_tagalys']));
                        $this->messageManager->addError("Sorry, something went wrong while saving your configuration. Please <a href=\"mailto:cs@tagalys.com\">email us</a> so we can resolve this issue.");
                        $redirectToTab = 'sync_settings';
                    }
                    break;
                case 'Save Search Settings':
                    $this->tagalysConfiguration->setConfig('module:search:enabled', $params['enable_search']);
                    $this->tagalysConfiguration->setConfig('search_box_selector', $params['search_box_selector']);
                    $this->tagalysConfiguration->setConfig('suggestions_align_to_parent_selector', $params['suggestions_align_to_parent_selector']);
                    $this->tagalysApi->log('warn', 'search:enabled:'.$params['enable_search']);
                    $redirectToTab = 'search';
                    break;
                case 'Save Listing Pages Settings':
                    if (array_key_exists('verified_with_tech_team', $params) && $params['verified_with_tech_team'] == '1') {
                        if (array_key_exists('override_layout_for_listing_pages', $params) && $params['override_layout_for_listing_pages'] == '1') {
                            $this->tagalysConfiguration->setConfig('listing_pages:override_layout', $params['override_layout_for_listing_pages']);
                        } else {
                            $this->tagalysConfiguration->setConfig('listing_pages:override_layout', '0');
                        }
                        $this->tagalysConfiguration->setConfig('listing_pages:override_layout_name', $params['override_layout_name_for_listing_pages']);
                        $this->tagalysConfiguration->setConfig('module:listingpages:enabled', $params['enable_listingpages']);
                        $this->tagalysApi->log('warn', 'listingpages:enabled:'.$params['enable_listingpages']);
                        if (!array_key_exists('categories_for_tagalys', $params)) {
                            $params['categories_for_tagalys'] = array();
                        }
                        $this->tagalysApi->log('info', 'Starting Listing Pages sync', array('categories_for_tagalys' => $params['categories_for_tagalys']));
                        $result = $this->tagalysConfiguration->syncCategories($params['categories_for_tagalys']);
                        if ($result === false) {
                            $this->tagalysApi->log('error', 'syncCategories returned false', array('categories_for_tagalys' => $params['categories_for_tagalys']));
                            $this->messageManager->addError("Sorry, something went wrong while saving Listing Pages configuration. We've logged the issue and we'll get back once we know more. You can contact us here: <a href=\"mailto:cs@tagalys.com\">cs@tagalys.com</a>");
                            $redirectToTab = 'listing_pages';
                        } else {
                            if ($result === true) {
                                $this->tagalysApi->log('info', 'Completed Listing Pages sync', array('categories_for_tagalys' => $params['categories_for_tagalys']));
                                $this->tagalysConfiguration->setConfig('categories', $params['categories_for_tagalys'], true);
                                $categoryIds = array();
                                foreach($params['categories_for_tagalys'] as $i => $categoryPath) {
                                    $path = explode('/', $categoryPath);
                                    $categoryIds[] = intval(end($path));
                                }
                                $this->tagalysConfiguration->setConfig('category_ids', $categoryIds, true);
                            } else {
                                // $result is an array of skipped category ids
                                $fitlered_categories_for_tagalys = array();
                                $filtered_category_ids_for_tagalys = array();
                                $this->tagalysApi->log('info', 'Completed Listing Pages sync', array('categories_for_tagalys' => $params['categories_for_tagalys'], 'skipped_categories' => $result));
                                foreach($params['categories_for_tagalys'] as $i => $categoryPath) {
                                    $path = explode('/', $categoryPath);
                                    $categoryId = intval(end($path));
                                    if (in_array($categoryId, $result)) {
                                        // skipped page
                                    } else {
                                        // okay...
                                        $fitlered_categories_for_tagalys[] = $categoryPath;
                                        $filtered_category_ids_for_tagalys[] = $categoryId;
                                    }
                                }
                                $this->tagalysConfiguration->setConfig('categories', $fitlered_categories_for_tagalys, true);
                                $this->tagalysConfiguration->setConfig('category_ids', $filtered_category_ids_for_tagalys, true);
                                $this->messageManager->addNoticeMessage("Some pages cannot be powered by Tagalys as there are no products available to create them. They have been automatically unselected from the list.");
                            }
                            $redirectToTab = 'listing_pages';
                        }
                    } else {
                        $this->messageManager->addError("Please consult with your tech team and double check your settings before proceeding. If you have done so, please check the box.");
                    }
                    $redirectToTab = 'listingpages';
                    break;
                case 'Save Recommendations Settings':
                    $this->tagalysConfiguration->setConfig('module:recommendations:enabled', $params['enable_recommendations']);
                    $this->tagalysApi->log('warn', 'search:enabled:'.$params['enable_recommendations']);
                    $redirectToTab = 'recommendations';
                    break;
                case 'Save My Store Settings':
                    $this->tagalysConfiguration->setConfig('module:mystore:enabled', $params['enable_mystore']);
                    $this->tagalysApi->log('warn', 'search:enabled:'.$params['enable_mystore']);
                    $redirectToTab = 'mystore';
                    break;
                case 'Update Popular Searches now':
                    $this->tagalysApi->log('warn', 'Triggering update popular searches');
                    $this->tagalysSync->cachePopularSearches();
                    $redirectToTab = 'support';
                    break;
                case 'Update Merchandised Pages cache now':
                    $this->tagalysApi->log('warn', 'Triggering update mpages cache');
                    $this->tagalysMpages->updateMpagesCache();
                    $redirectToTab = 'support';
                    break;
                case 'Trigger full products resync now':
                    $this->tagalysApi->log('warn', 'Triggering full products resync');
                    foreach ($this->tagalysConfiguration->getStoresForTagalys() as $storeId) {
                        $this->tagalysSync->triggerFeedForStore($storeId);
                    }
                    $this->queueHelper->truncate();
                    $redirectToTab = 'support';
                    break;
                case 'Clear Tagalys sync queue':
                    $this->tagalysApi->log('warn', 'Clearing Tagalys sync queue');
                    $this->queueHelper->truncate();
                    $redirectToTab = 'support';
                    break;
                case 'Trigger configuration resync now':
                    $this->tagalysApi->log('warn', 'Triggering configuration resync');
                    $this->tagalysConfiguration->setConfig("config_sync_required", '1');
                    $redirectToTab = 'support';
                    break;
                case 'Restart Tagalys Setup':
                    $this->tagalysApi->log('warn', 'Restarting Tagalys Setup');
                    $this->queueHelper->truncate();
                    $this->tagalysConfiguration->truncate();
                    $redirectToTab = 'api_credentials';
                    break;
            }
            return $this->_redirect('tagalys/configuration/edit/active_tab/'.$redirectToTab);
        }

        return  $resultPage;
    }

    protected function _saveApiCredentials($params)
    {
        $result = $this->tagalysApi->identificationCheck(json_decode($params['api_credentials'], true));
        if ($result['result'] != 1) {
            $this->messageManager->addError("Invalid API Credentials. Please try again. If you continue having issues, please <a href=\"mailto:cs@tagalys.com\">email us</a>.");
            return false;
        }
        // save credentials
        $this->tagalysConfiguration->setConfig('api_credentials', $params['api_credentials']);
        $this->tagalysApi->cacheApiCredentials();
        return true;
    }
  }