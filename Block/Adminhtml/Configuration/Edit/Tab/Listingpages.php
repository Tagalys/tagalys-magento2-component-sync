<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

/**
 * Product attribute add/edit form main tab
 *
 * @author      Magento Core Team <core@magentocommerce.com>
 */
namespace Tagalys\Sync\Block\Adminhtml\Configuration\Edit\Tab;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Eav\Block\Adminhtml\Attribute\PropertyLocker;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;

/**
 * @api
 * @since 100.0.2
 */
class Listingpages extends Generic
{
    /**
     * @var Yesno
     */
    protected $_yesNo;

    /**
     * @var PropertyLocker
     */
    private $propertyLocker;

    /**
     * @param Context $context
     * @param Registry $registry
     * @param FormFactory $formFactory
     * @param Yesno $yesNo
     * @param PropertyLocker $propertyLocker
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        Yesno $yesNo,
        PropertyLocker $propertyLocker,
        \Tagalys\Sync\Helper\Configuration $tagalysConfiguration,
        \Tagalys\Sync\Helper\Api $tagalysApi,
        array $data = []
    ) {
        $this->_yesNo = $yesNo;
        $this->propertyLocker = $propertyLocker;
        $this->tagalysConfiguration = $tagalysConfiguration;
        $this->tagalysApi = $tagalysApi;
        parent::__construct($context, $registry, $formFactory, $data);
    }

    /**
     * {@inheritdoc}
     * @return $this
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function _prepareForm()
    {

        /** @var \Magento\Framework\Data\Form $form */
        $form = $this->_formFactory->create(
            ['data' => ['id' => 'edit_form', 'action' => $this->getData('action'), 'method' => 'post']]
        );

        // $yesnoSource = $this->_yesNo->toOptionArray();

        $listingpagesFieldset = $form->addFieldset(
            'listingpages_fieldset',
            ['legend' => __('Listing Pages'), 'collapsable' => $this->getRequest()->has('popup')]
        );

        $listingpagesFieldset->addField('enable_listingpages', 'select', array(
            'name' => 'enable_listingpages',
            'label' => 'Use Tagalys to power Category pages',
            'title' => 'Use Tagalys to power Category pages',
            'options' => array(
                '0' => __('No'),
                '1' => __('Yes - For selected category pages')
            ),
            'required' => true,
            'style' => 'width:100%',
            'value' => $this->tagalysConfiguration->getConfig("module:listingpages:enabled")
        ));

        $listingpagesFieldset->addField('categories_for_tagalys', 'multiselect', array(
            'label' => __('If "Yes - For selected category pages", select categories'),
            'name'      => 'categories_for_tagalys',
            'onclick' => "return false;",
            'onchange' => "return false;",
            'value'  => $this->tagalysConfiguration->getCategoriesForTagalys(),
            'values' => $this->tagalysConfiguration->getAllCategories(),
            'style' => "width:100%; height: 400px;",
            'disabled' => false,
            'readonly' => false,
            'tabindex' => 1
        ));

        $listingpagesTechFieldset = $form->addFieldset(
            'listingpages_tech_fieldset',
            ['legend' => __('Technical Considerations'), 'collapsable' => $this->getRequest()->has('popup')]
        );

        $listingpagesTechFieldset->addField('note', 'note', array(
            'label' => __('Notes for the tech team'),
            'text' => '<ul>
                <li>Tagalys will replace the template used to render the <em>category.products</em> block, so make sure that this is present in your layout</li>
                <li>Since Tagalys renders filters and products within this block, recommended settings are to override the layout and use <em>1column</em></li>
                <li>Overriding will make the page appear like categories for Tagalys are not Anchors and don\'t have children</li>
                <li>If you have some custom UI rules common to all Tagalys pages, then you could create a separate custom layout and override that instead of <em>1column</em></li>
                <li>If you need control over each page, avoid overriding the layout and use Magento controls under Catalog&nbsp;>&nbsp;Category for each page to specify the layout and any updates you need</li>
                <li>You may have to clear your Magento cache after updating these settings</li>
                <li>Please contact cs@tagalys.com if you have any questions</li>
            </ul>',
        ));

        $listingpagesTechFieldset->addField('override_layout_for_listing_pages', 'select', array(
            'name' => 'override_layout_for_listing_pages',
            'label' => 'Override layout for Tagalys powered category pages',
            'title' => 'Override layout for Tagalys powered category pages',
            'options' => array(
                '0' => __('No'),
                '1' => __('Yes')
            ),
            'required' => true,
            'style' => 'width:100%',
            'value' => $this->tagalysConfiguration->getConfig("listing_pages:override_layout")
        ));

        $listingpagesTechFieldset->addField('override_layout_name_for_listing_pages', 'text', array(
            'name'      => 'override_layout_name_for_listing_pages',
            'label'     => __('Layout name to override with'),
            'value'  => $this->tagalysConfiguration->getConfig("listing_pages:override_layout_name"),
            'required'  => true,
            'style'   => "width:100%",
            'tabindex' => 1
        ));

        $listingpagesTechFieldset->addField(
            'verified_with_tech_team',
            'checkbox',
            [
                'label' => 'Tech team has verified Tagalys settings for listing pages',
                'name' => 'verified_with_tech_team',
                'data-form-part' => $this->getData('verified_with_tech_team'),
                'onchange'   => 'this.value = this.checked ? 1 : 0;',
                'after_element_html' => '<br><br>Please consult with your tech team and double check your settings before proceeding.',
            ]
        );


        $listingpagesTechFieldset->addField('submit', 'submit', array(
            'name' => 'tagalys_submit_action',
            'value' => 'Save Listing Pages Settings',
            'class' => 'tagalys-button-submit'
        ));

        $this->setForm($form);
        // $this->propertyLocker->lock($form);
        return parent::_prepareForm();
    }
}
