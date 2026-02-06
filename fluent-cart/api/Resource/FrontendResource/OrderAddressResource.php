<?php

namespace FluentCart\Api\Resource\FrontendResource;

use FluentCart\Api\Resource\BaseResourceApi;
use FluentCart\App\Models\OrderAddress;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class OrderAddressResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return OrderAddress::query();
    }

    /**
     * Get order addresses with specified parameters.
     *
     * @param array $params Array containing the necessary parameters.
     *
     */
    public static function get(array $params = [])
    {
        //
    }

    /**
     * Find order address based on the given ID and parameters.
     *
     * @param int $id Optional. The ID of the order address.
     * @param array $params Optional. Array containing the necessary parameters.
     *
     */
    public static function find($id = null, $params = [])
    {
        $address = null;

        if ($id) {
            $address = static::getQuery()->find($id);
        }

        $orderId = Arr::get($params, 'order_id');

        if (empty($address) && !empty($orderId)) {
            $type = Arr::get($params, 'type', 'billing');

            $address = static::getQuery()
                ->where('order_id', $orderId)
                ->where('type', $type)
                ->first();
        }

        return $address;
    }

    /**
     * Create order address with the given data.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        [
     *              'order_id'     => (int) Required. The id of the order.
     *              'name'         => (string) Required. The name of the address.
     *              'address_1'    => (string) Required. The primary address line.
     *              'address_2'    => (string) Optional. The secondary address line.
     *              'city'         => (string) Required. The city of the address.
     *              'country'      => (string) Required. The country of the address.
     *              'postcode'     => (string) Required. The postal code of the address.
     *              'state'        => (string) Required. The state of the address.
     *              'type'         => (string) Required. (e.g., 'billing', 'shipping').
     *        ]
     * @param array $params Required. Additional parameters for creating an address.
     * 
     */
    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Order address created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Order address creation failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Update order address with the given data.
     *
     * @param array $data Required. Array containing the necessary parameters
     * @param int $id Required. The ID of the order address to be updated.
     *
     */
    public static function update($data, $id, $params = [])
    {
        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please edit a valid address!', 'fluent-cart')]
            ]);
        }

        $address = static::getQuery()->find($id);

        if (!$address) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Address not found, please reload the page and try again!', 'fluent-cart')]
            ]);
        }

        $isUpdated = $address->update($data);

        if ($isUpdated) {
            static::makeSuccessResponse($isUpdated, __('Order address updated successfully!', 'fluent-cart'));
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Order address update failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Delete an address based on the given ID and parameters.
     *
     * @param int $id Required. The ID of the order address.
     * @param array $params Optional. Additional parameters for address deletion.
     *
     */
    public static function delete($id, $params = [])
    {
        //
    }
}