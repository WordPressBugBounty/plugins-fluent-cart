<?php

namespace FluentCart\App\Services\Renderer;

class RenderHelper
{
    public static function renderAtts($atts = [])
    {
        foreach ($atts as $attr => $value) {
            if ($value !== '') {
                echo esc_attr($attr) . '="' . esc_attr((string)$value) . '" ';
            } else {
                echo esc_attr($attr) . ' ';
            }
        }
    }

    public static function getBlockWrapperAttributes(array $attributes = [])
    {
        if (
            !empty(\WP_Block_Supports::$block_to_render)
            && is_array(\WP_Block_Supports::$block_to_render)
            && !empty(\WP_Block_Supports::$block_to_render['blockName'])
        ) {
            return get_block_wrapper_attributes($attributes);
        }

        ob_start();
        static::renderAtts($attributes);
        return ob_get_clean();
    }
}
