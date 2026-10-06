<?php

namespace App\Services\Shopify;

use Osiset\ShopifyApp\Contracts\ShopModel;

/**
 * Reads products (with their options), their variants (with selected option values) and per-location inventory quantities
 * through the Shopify Admin GraphQL API.
 *
 * Products and variants are fetched in separate queries: nesting
 * products → variants → inventory levels in one query quickly exceeds
 * Shopify's 1000-point single query cost limit.
 */
class ProductInventoryService
{
    /** Inventory quantity states returned for every location. */
    public const QUANTITY_NAMES = [
        'available',
        'on_hand',
        'committed',
        'incoming',
        'reserved',
        'damaged',
        'quality_control',
        'safety_stock',
    ];

    /** Variants per productVariants query; keeps query cost under ~900 points. */
    private const VARIANTS_PER_QUERY = 25;

    /** Inventory levels (locations) fetched inline per variant; extra ones are paged separately. */
    private const LEVELS_PER_VARIANT = 10;

    /** Query cost budget for the products page; sizes how many images are fetched inline per product. */
    private const PRODUCTS_QUERY_BUDGET = 900;

    /** Images fetched inline per product at most; extra ones are paged separately. */
    private const MAX_IMAGES_PER_PRODUCT = 20;

    private const MAX_THROTTLE_RETRIES = 3;

    private const PRODUCT_FIELDS = <<<'GQL'
        fragment ProductFields on Product {
          id
          title
          handle
          status
          description
          descriptionHtml
          priceRangeV2 {
            minVariantPrice { amount currencyCode }
            maxVariantPrice { amount currencyCode }
          }
          options {
            id
            name
            position
            optionValues { id name hasVariants }
          }
          media(first: $mediaFirst) {
            pageInfo { hasNextPage endCursor }
            nodes { ...MediaFields }
          }
        }
        GQL.self::MEDIA_FIELDS;

    private const MEDIA_FIELDS = <<<'GQL'

        fragment MediaFields on Media {
          ... on MediaImage {
            id
            image { url altText width height }
          }
        }
        GQL;

    private const VARIANT_FIELDS = <<<'GQL'
        fragment VariantFields on ProductVariant {
          id
          title
          sku
          barcode
          position
          price
          compareAtPrice
          inventoryQuantity
          selectedOptions { name value }
          product { id }
          inventoryItem {
            id
            tracked
            inventoryLevels(first: $levelsFirst) {
              pageInfo { hasNextPage endCursor }
              nodes { ...LevelFields }
            }
          }
        }

        fragment LevelFields on InventoryLevel {
          location { id name }
          quantities(names: $quantityNames) { name quantity }
        }
        GQL;

    public function __construct(private readonly ShopModel $shop)
    {
    }

    /**
     * One page of products with variants and inventory.
     *
     * @return array{products: array<int, array>, page_info: array{has_next_page: bool, end_cursor: ?string}}
     */
    public function paginate(int $perPage = 20, ?string $cursor = null): array
    {
        $data = $this->query(<<<'GQL'
            query ($first: Int!, $after: String, $mediaFirst: Int!) {
              products(first: $first, after: $after, sortKey: ID) {
                pageInfo { hasNextPage endCursor }
                nodes { ...ProductFields }
              }
            }
            GQL.self::PRODUCT_FIELDS, [
            'first' => $perPage,
            'after' => $cursor,
            // Roughly 8 points per product plus 2 per image, times the page size.
            'mediaFirst' => max(1, min(
                self::MAX_IMAGES_PER_PRODUCT,
                intdiv(intdiv(self::PRODUCTS_QUERY_BUDGET, $perPage) - 8, 2),
            )),
        ]);

        $products = $data['products']['nodes'];

        return [
            'products' => $this->attachVariants($products),
            'page_info' => [
                'has_next_page' => $data['products']['pageInfo']['hasNextPage'],
                'end_cursor' => $data['products']['pageInfo']['endCursor'],
            ],
        ];
    }

    /**
     * Iterate over every product in the store, one page at a time.
     *
     * @return \Generator<int, array>
     */
    public function all(int $perPage = 20): \Generator
    {
        $cursor = null;

        do {
            $page = $this->paginate($perPage, $cursor);

            yield from $page['products'];

            $cursor = $page['page_info']['end_cursor'];
        } while ($page['page_info']['has_next_page']);
    }

    public function findByProductId(string|int $productId): ?array
    {
        $data = $this->query(<<<'GQL'
            query ($id: ID!, $mediaFirst: Int!) {
              product(id: $id) { ...ProductFields }
            }
            GQL.self::PRODUCT_FIELDS, [
            'id' => $this->toGid('Product', $productId),
            'mediaFirst' => 100,
        ]);

        if (! $data['product']) {
            return null;
        }

        return $this->attachVariants([$data['product']])[0];
    }

    public function findBySku(string $sku): ?array
    {
        return $this->findByVariantField('sku', $sku);
    }

    public function findByBarcode(string $barcode): ?array
    {
        return $this->findByVariantField('barcode', $barcode);
    }

    /**
     * Search is token-based, so candidates are re-checked for an exact match.
     */
    private function findByVariantField(string $field, string $value): ?array
    {
        $data = $this->query(<<<'GQL'
            query ($query: String!) {
              productVariants(first: 10, query: $query) {
                nodes { sku barcode product { id } }
              }
            }
            GQL, ['query' => sprintf('%s:"%s"', $field, addcslashes($value, '"\\'))]);

        foreach ($data['productVariants']['nodes'] as $variant) {
            if ($variant[$field] === $value) {
                return $this->findByProductId($variant['product']['id']);
            }
        }

        return null;
    }

    /**
     * @param  array<int, array>  $products  Raw ProductFields nodes.
     */
    private function attachVariants(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $variantsByProduct = $this->variantsForProducts(array_column($products, 'id'));

        return array_map(fn (array $product) => [
            'id' => $this->toLegacyId($product['id']),
            'gid' => $product['id'],
            'title' => $product['title'],
            'handle' => $product['handle'],
            'status' => $product['status'],
            'description' => $product['description'],
            'description_html' => $product['descriptionHtml'],
            'price_range' => [
                'min' => $product['priceRangeV2']['minVariantPrice']['amount'],
                'max' => $product['priceRangeV2']['maxVariantPrice']['amount'],
                'currency' => $product['priceRangeV2']['minVariantPrice']['currencyCode'],
            ],
            'options' => array_map($this->formatOption(...), $product['options']),
            'images' => $this->formatImages($product),
            'variants' => $variantsByProduct[$product['id']] ?? [],
        ], $products);
    }

    private function formatOption(array $option): array
    {
        return [
            'id' => $this->toLegacyId($option['id']),
            'name' => $option['name'],
            'position' => $option['position'],
            'values' => array_map(fn (array $value) => [
                'id' => $this->toLegacyId($value['id']),
                'name' => $value['name'],
                'has_variants' => $value['hasVariants'],
            ], $option['optionValues']),
        ];
    }

    private function formatImages(array $product): array
    {
        $media = $product['media']['nodes'];

        if ($product['media']['pageInfo']['hasNextPage']) {
            $media = array_merge($media, $this->remainingMedia(
                $product['id'],
                $product['media']['pageInfo']['endCursor'],
            ));
        }

        // Non-image media (videos, 3D models) come back as empty objects.
        $images = array_filter($media, fn (array $node) => isset($node['image']));

        return array_values(array_map(fn (array $node) => [
            'id' => $this->toLegacyId($node['id']),
            'url' => $node['image']['url'],
            'alt' => $node['image']['altText'],
            'width' => $node['image']['width'],
            'height' => $node['image']['height'],
        ], $images));
    }

    /**
     * Media beyond the first page, for products with many images.
     */
    private function remainingMedia(string $productGid, string $cursor): array
    {
        $media = [];

        do {
            $data = $this->query(<<<'GQL'
                query ($id: ID!, $after: String) {
                  product(id: $id) {
                    media(first: 100, after: $after) {
                      pageInfo { hasNextPage endCursor }
                      nodes { ...MediaFields }
                    }
                  }
                }
                GQL.self::MEDIA_FIELDS, ['id' => $productGid, 'after' => $cursor]);

            $connection = $data['product']['media'];
            $media = array_merge($media, $connection['nodes']);
            $cursor = $connection['pageInfo']['endCursor'];
        } while ($connection['pageInfo']['hasNextPage']);

        return $media;
    }

    /**
     * @param  string[]  $productGids
     * @return array<string, array<int, array>> Formatted variants keyed by product GID, in position order.
     */
    private function variantsForProducts(array $productGids): array
    {
        $search = implode(' OR ', array_map(
            fn (string $gid) => 'product_id:'.$this->toLegacyId($gid),
            $productGids,
        ));

        $grouped = [];
        $cursor = null;

        do {
            $data = $this->query(<<<'GQL'
                query ($first: Int!, $after: String, $query: String!, $levelsFirst: Int!, $quantityNames: [String!]!) {
                  productVariants(first: $first, after: $after, query: $query) {
                    pageInfo { hasNextPage endCursor }
                    nodes { ...VariantFields }
                  }
                }
                GQL.self::VARIANT_FIELDS, [
                'first' => self::VARIANTS_PER_QUERY,
                'after' => $cursor,
                'query' => $search,
                'levelsFirst' => self::LEVELS_PER_VARIANT,
                'quantityNames' => self::QUANTITY_NAMES,
            ]);

            foreach ($data['productVariants']['nodes'] as $variant) {
                $grouped[$variant['product']['id']][] = $this->formatVariant($variant);
            }

            $pageInfo = $data['productVariants']['pageInfo'];
            $cursor = $pageInfo['endCursor'];
        } while ($pageInfo['hasNextPage']);

        foreach ($grouped as &$variants) {
            usort($variants, fn (array $a, array $b) => $a['position'] <=> $b['position']);
        }

        return $grouped;
    }

    private function formatVariant(array $variant): array
    {
        $item = $variant['inventoryItem'];
        $levels = $item['inventoryLevels']['nodes'];

        if ($item['inventoryLevels']['pageInfo']['hasNextPage']) {
            $levels = array_merge($levels, $this->remainingLevels(
                $item['id'],
                $item['inventoryLevels']['pageInfo']['endCursor'],
            ));
        }

        return [
            'id' => $this->toLegacyId($variant['id']),
            'gid' => $variant['id'],
            'title' => $variant['title'],
            'sku' => $variant['sku'],
            'barcode' => $variant['barcode'],
            'position' => $variant['position'],
            'price' => $variant['price'],
            'compare_at_price' => $variant['compareAtPrice'],
            'options' => $variant['selectedOptions'],
            'inventory_item_id' => $this->toLegacyId($item['id']),
            'inventory_tracked' => $item['tracked'],
            'total_available' => $variant['inventoryQuantity'],
            'inventory' => array_map($this->formatLevel(...), $levels),
        ];
    }

    private function formatLevel(array $level): array
    {
        $quantities = array_fill_keys(self::QUANTITY_NAMES, 0);

        foreach ($level['quantities'] as $quantity) {
            $quantities[$quantity['name']] = $quantity['quantity'];
        }

        return [
            'location_id' => $this->toLegacyId($level['location']['id']),
            'location_name' => $level['location']['name'],
            'quantities' => $quantities,
        ];
    }

    /**
     * Inventory levels beyond the first page, for items stocked at many locations.
     */
    private function remainingLevels(string $inventoryItemGid, string $cursor): array
    {
        $levels = [];

        do {
            $data = $this->query(<<<'GQL'
                query ($id: ID!, $after: String, $quantityNames: [String!]!) {
                  inventoryItem(id: $id) {
                    inventoryLevels(first: 50, after: $after) {
                      pageInfo { hasNextPage endCursor }
                      nodes {
                        location { id name }
                        quantities(names: $quantityNames) { name quantity }
                      }
                    }
                  }
                }
                GQL, [
                'id' => $inventoryItemGid,
                'after' => $cursor,
                'quantityNames' => self::QUANTITY_NAMES,
            ]);

            $connection = $data['inventoryItem']['inventoryLevels'];
            $levels = array_merge($levels, $connection['nodes']);
            $cursor = $connection['pageInfo']['endCursor'];
        } while ($connection['pageInfo']['hasNextPage']);

        return $levels;
    }

    /**
     * Run a GraphQL query and return its `data`, retrying when Shopify throttles.
     */
    private function query(string $query, array $variables = []): array
    {
        for ($attempt = 0; ; $attempt++) {
            $response = $this->shop->api()->graph($query, $variables);

            if (! $response['errors']) {
                return $response['body']->toArray()['data'];
            }

            // GraphQL errors (HTTP 200) arrive in `errors`; HTTP failures set `errors` to true and put them in `body`.
            $errors = $response['errors'] === true ? $response['body'] : $response['errors'];

            if ($attempt < self::MAX_THROTTLE_RETRIES && $this->isThrottled($errors)) {
                sleep(2 ** $attempt);

                continue;
            }

            throw new ShopifyGraphQLException('Shopify GraphQL request failed.', $errors);
        }
    }

    private function isThrottled(mixed $errors): bool
    {
        foreach ((array) $errors as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    private function toGid(string $type, string|int $id): string
    {
        return str_starts_with((string) $id, 'gid://') ? (string) $id : "gid://shopify/{$type}/{$id}";
    }

    private function toLegacyId(string $gid): int
    {
        return (int) substr($gid, strrpos($gid, '/') + 1);
    }
}
