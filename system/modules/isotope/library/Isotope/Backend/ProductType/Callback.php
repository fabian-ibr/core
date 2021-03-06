<?php

/**
 * Isotope eCommerce for Contao Open Source CMS
 *
 * Copyright (C) 2009-2014 terminal42 gmbh & Isotope eCommerce Workgroup
 *
 * @package    Isotope
 * @link       http://isotopeecommerce.org
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */

namespace Isotope\Backend\ProductType;

use Isotope\Backend\Permission;
use Isotope\Interfaces\IsotopeAttributeForVariants;
use Isotope\Model\Attribute;
use Isotope\Model\Product;
use Isotope\Model\ProductType;


class Callback extends Permission
{

    /**
     * Check permissions to edit table tl_iso_producttype
     */
    public function checkPermission()
    {
        // Do not run the permission check on other Isotope modules
        if (\Input::get('mod') != 'producttypes') {
            return;
        }

        /** @type \BackendUser $objBackendUser */
        $objBackendUser = \BackendUser::getInstance();

        if ($objBackendUser->isAdmin) {
            return;
        }

        // Set root IDs
        if (!is_array($objBackendUser->iso_product_types) || count($objBackendUser->iso_product_types) < 1) // Can't use empty() because its an object property (using __get)
        {
            $root = array(0);
        } else {
            $root = $objBackendUser->iso_product_types;
        }

        $GLOBALS['TL_DCA']['tl_iso_producttype']['list']['sorting']['root'] = $root;

        // Check permissions to add product types
        if (!$objBackendUser->hasAccess('create', 'iso_product_typep')) {
            $GLOBALS['TL_DCA']['tl_iso_producttype']['config']['closed'] = true;
            unset($GLOBALS['TL_DCA']['tl_iso_producttype']['list']['global_operations']['new']);
        }

        // Check current action
        switch (\Input::get('act')) {
            case 'create':
            case 'select':
                // Allow
                break;

            /** @noinspection PhpMissingBreakStatementInspection */
            case 'edit':
                // Dynamically add the record to the user profile
                if (!in_array(\Input::get('id'), $root)
                    && $this->addNewRecordPermissions(\Input::get('id'), 'iso_product_types', 'iso_product_typep')
                ) {
                    $root[]                            = \Input::get('id');
                    $objBackendUser->iso_product_types = $root;
                }
                // No break;

            case 'copy':
            case 'delete':
            case 'show':
                if (!in_array(\Input::get('id'), $root) || (\Input::get('act') == 'delete' && !$objBackendUser->hasAccess('delete', 'iso_product_typep'))) {
                    \System::log('Not enough permissions to ' . \Input::get('act') . ' product type ID "' . \Input::get('id') . '"', __METHOD__, TL_ERROR);
                    \Controller::redirect('contao/main.php?act=error');
                }
                break;

            case 'editAll':
            case 'deleteAll':
            case 'overrideAll':
                $session = $this->Session->getData();
                if (\Input::get('act') == 'deleteAll' && !$this->User->hasAccess('delete', 'iso_product_typep')) {
                    $session['CURRENT']['IDS'] = array();
                } else {
                    $session['CURRENT']['IDS'] = array_intersect($session['CURRENT']['IDS'], $root);
                }
                $this->Session->setData($session);
                break;

            default:
                if (strlen(\Input::get('act'))) {
                    \System::log('Not enough permissions to ' . \Input::get('act') . ' product types', __METHOD__, TL_ERROR);
                    \Controller::redirect('contao/main.php?act=error');
                }
                break;
        }
    }


    /**
     * Return the copy product type button
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     *
     * @return string
     */
    public function copyProductType($row, $href, $label, $title, $icon, $attributes)
    {
        /** @type \BackendUser $objUser */
        $objUser = \BackendUser::getInstance();

        return ($objUser->isAdmin || $objUser->hasAccess('create', 'iso_product_typep')) ? '<a href="' . $this->addToUrl($href . '&amp;id=' . $row['id']) . '" title="' . specialchars($title) . '"' . $attributes . '>' . \Image::getHtml($icon, $label) . '</a> ' : \Image::getHtml(preg_replace('/\.gif$/i', '_.gif', $icon)) . ' ';
    }


    /**
     * Return the delete product type button
     *
     * @param array  $row
     * @param string $href
     * @param string $label
     * @param string $title
     * @param string $icon
     * @param string $attributes
     *
     * @return string
     */
    public function deleteProductType($row, $href, $label, $title, $icon, $attributes)
    {
        if (Product::countBy('type', $row['id']) > 0) {
            return \Image::getHtml(preg_replace('/\.gif$/i', '_.gif', $icon)) . ' ';
        }

        /** @type \BackendUser $objUser */
        $objUser = \BackendUser::getInstance();

        return ($objUser->isAdmin || $objUser->hasAccess('delete', 'iso_product_typep')) ? '<a href="' . $this->addToUrl($href . '&amp;id=' . $row['id']) . '" title="' . specialchars($title) . '"' . $attributes . '>' . \Image::getHtml($icon, $label) . '</a> ' : \Image::getHtml(preg_replace('/\.gif$/i', '_.gif', $icon)) . ' ';
    }

    /**
     * Returns all allowed product types as array
     *
     * @return array
     */
    public function getOptions()
    {
        /** @type \BackendUser $objUser */
        $objUser = \BackendUser::getInstance();

        $arrTypes = $objUser->iso_product_types;

        if (!$objUser->isAdmin && (!is_array($arrTypes) || empty($arrTypes))) {
            $arrTypes = array(0);
        }

        $t = ProductType::getTable();
        $arrProductTypes = array();
        $objProductTypes = \Database::getInstance()->execute("
            SELECT id,name FROM $t
            WHERE tstamp>0" . ($objUser->isAdmin ? '' : (" AND id IN (" . implode(',', $arrTypes) . ")")) . "
            ORDER BY name
        ");

        while ($objProductTypes->next()) {
            $arrProductTypes[$objProductTypes->id] = $objProductTypes->name;
        }

        return $arrProductTypes;
    }

    /**
     * Make sure at least one variant attribute is enabled
     *
     * @param mixed $varValue
     *
     * @return mixed
     * @throws \UnderflowException
     */
    public function validateVariantAttributes($varValue)
    {
        \Controller::loadDataContainer('tl_iso_product');

        $blnError = true;
        $arrAttributes = deserialize($varValue);
        $arrVariantAttributeLabels = array();

        if (!empty($arrAttributes) && is_array($arrAttributes)) {
            foreach ($arrAttributes as $arrAttribute) {

                /** @type IsotopeAttributeForVariants|Attribute $objAttribute */
                $objAttribute = $GLOBALS['TL_DCA']['tl_iso_product']['attributes'][$arrAttribute['name']];

                if (null !== $objAttribute && /* @todo in 3.0: $objAttribute instanceof IsotopeAttributeForVariants && */$objAttribute->isVariantOption()) {
                    $arrVariantAttributeLabels[] = $objAttribute->name;

                    if ($arrAttribute['enabled']) {
                        $blnError = false;
                    }
                }
            }
        }

        if ($blnError) {
            \System::loadLanguageFile('explain');
            throw new \UnderflowException(
                sprintf($GLOBALS['TL_LANG']['tl_iso_producttype']['noVariantAttributes'],
                    implode(', ', $arrVariantAttributeLabels)
                )
            );
        }

        return $varValue;
    }
}
