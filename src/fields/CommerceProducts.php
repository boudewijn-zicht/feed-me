<?php

namespace craft\feedme\fields;

use Cake\Utility\Hash;
use Craft;
use craft\commerce\elements\Product as ProductElement;
use craft\commerce\fields\Products;
use craft\feedme\base\Field;
use craft\feedme\base\FieldInterface;
use craft\feedme\helpers\DataHelper;
use craft\feedme\Plugin;
use craft\helpers\Db;
use craft\helpers\Json;

/**
 *
 * @property-read string $mappingTemplate
 */
class CommerceProducts extends Field implements FieldInterface
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public static string $name = 'CommerceProducts';

    /**
     * @var string
     */
    public static string $class = Products::class;

    /**
     * @var string
     */
    public static string $elementType = ProductElement::class;

    // Templates
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getMappingTemplate(): string
    {
        return 'feed-me/_includes/fields/commerce_products';
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function parseField(): mixed
    {
        $value = $this->fetchArrayValue();
        $default = $this->fetchDefaultArrayValue();

        // if the mapped value is not set in the feed
        if ($value === null) {
            return null;
        }

        // if value from the feed is empty and default is not set
        // return an empty array; no point bothering further
        if (empty($default) && DataHelper::isArrayValueEmpty($value)) {
            return [];
        }

        $sources = Hash::get($this->field, 'settings.sources');
        $limit = Hash::get($this->field, 'settings.limit');
        $targetSiteId = Hash::get($this->field, 'settings.targetSiteId');
        $feedSiteId = Hash::get($this->feed, 'siteId');
        $match = Hash::get($this->fieldInfo, 'options.match', 'title');
        $node = Hash::get($this->fieldInfo, 'node');

        $typeIds = [];

        if (is_array($sources)) {
            foreach ($sources as $source) {
                [, $uid] = explode(':', $source);
                $typeIds[] = Db::idByUid('{{%commerce_producttypes}}', $uid);
            }
        } elseif ($sources === '*') {
            $typeIds = null;
        }

        $foundElements = [];

        foreach ($value as $dataValue) {
            // Prevent empty or blank values (string or array), which match all elements
            if (empty($dataValue) && empty($default)) {
                continue;
            }

            // If we're using the default value - skip, we've already got an id array
            if ($node === 'usedefault') {
                $foundElements = $value;
                break;
            }

            // special provision for falling back on default BaseRelationField value
            // https://github.com/craftcms/feed-me/issues/1195
            if (trim($dataValue) === '') {
                $foundElements = $default;
                break;
            }

            // Because we can match on element attributes and custom fields, AND we're directly using SQL
            // queries in our `where` below, we need to check if we need a prefix for custom fields accessing
            // the content table.
            $columnName = $match;

            if (Craft::$app->getFields()->getFieldByHandle($match)) {
                $columnName = Craft::$app->getFields()->oldFieldColumnPrefix . $match;
            }

            $query = ProductElement::find();

            // In multi-site, there's currently no way to query across all sites - we use the current site
            // See https://github.com/craftcms/cms/issues/2854
            if (Craft::$app->getIsMultiSite()) {
                if ($targetSiteId) {
                    $criteria['siteId'] = Craft::$app->getSites()->getSiteByUid($targetSiteId)->id;
                } elseif ($feedSiteId) {
                    $criteria['siteId'] = $feedSiteId;
                } else {
                    $criteria['siteId'] = Craft::$app->getSites()->getCurrentSite()->id;
                }
            }

            $criteria['status'] = null;
            $criteria['typeId'] = $typeIds;
            $criteria['limit'] = $limit;
            $criteria['where'] = ['=', $columnName, $dataValue];

            Craft::configure($query, $criteria);

            Plugin::info('Search for existing product with query `{i}`', ['i' => Json::encode($criteria)]);

            $ids = $query->ids();

            $foundElements = array_merge($foundElements, $ids);

            Plugin::info('Found `{i}` existing products: `{j}`', ['i' => count($foundElements), 'j' => Json::encode($foundElements)]);
        }

        // Check for field limit - only return the specified amount
        if ($foundElements && $limit) {
            $foundElements = array_chunk($foundElements, $limit)[0];
        }

        $foundElements = array_unique($foundElements);

        // Protect against sending an empty array - removing any existing elements
        if (!$foundElements) {
            return null;
        }

        return $foundElements;
    }
}
