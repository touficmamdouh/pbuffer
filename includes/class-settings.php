<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Settings {

    public const OPTION = 'lightweb2_settings';

    public static function defaults(): array {
        return [
            'token'           => '',
            'channels'        => [],
            'default_mode'    => 'addToQueue',
            'template'        => "{title}\n\n{excerpt}\n\n{url}\n\n{tags}",
            'category_filter' => 0,
            'retention_days'  => 30,
        ];
    }

    public function get(): array {
        $value = get_option( self::OPTION, [] );
        $value = is_array( $value ) ? $value : [];

        return wp_parse_args( $value, self::defaults() );
    }

    public function register(): void {
        // Settings UI hooks are added by the settings feature.
    }

    public static function catalog_key( string $token ): string {
        return 'lightweb2_channels_' . substr( md5( $token ), 0, 12 );
    }
}
