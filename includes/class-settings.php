<?php
declare( strict_types=1 );

namespace LightWeb2\PublishWithBuffer;

final class Settings {

    public const OPTION = 'lightweb2_settings';

    public function register(): void {
        add_action( 'admin_init', [ $this, 'register_setting' ] );
    }

    public function register_setting(): void {
        register_setting(
            'lightweb2_settings_group',
            self::OPTION,
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize' ],
                'default'           => self::defaults(),
            ]
        );
    }

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

    public static function catalog_key( string $token ): string {
        return 'lightweb2_channels_' . substr( md5( $token ), 0, 12 );
    }

    public function sanitize( $input ): array {
        $input   = is_array( $input ) ? $input : [];
        $current = $this->get();

        $token = isset( $input['token'] )
            ? sanitize_text_field( (string) $input['token'] )
            : '';

        if ( '' === $token ) {
            $token = (string) $current['token'];
        }

        $token_changed = $token !== (string) $current['token'];

        if ( $token_changed ) {
            delete_transient( self::catalog_key( (string) $current['token'] ) );
            delete_transient( self::catalog_key( $token ) );
            $channels = [];
            if ( function_exists( 'add_settings_error' ) ) {
                add_settings_error(
                    'lightweb2',
                    'lightweb2_token_changed',
                    __( 'The Buffer token changed. Load and select your channels again.', 'lightweb2-publish-with-buffer' ),
                    'warning'
                );
            }
        } elseif ( ! empty( $input['channels_submitted'] ) ) {
            $channels = $this->sanitize_channels( $input['channels'] ?? [], (string) $current['token'] );
        } else {
            $channels = is_array( $current['channels'] ) ? $current['channels'] : [];
        }

        $mode = isset( $input['default_mode'] )
            ? sanitize_text_field( (string) $input['default_mode'] )
            : (string) $current['default_mode'];

        if ( ! in_array( $mode, [ 'shareNow', 'addToQueue' ], true ) ) {
            $mode = 'addToQueue';
        }

        $template = isset( $input['template'] )
            ? sanitize_textarea_field( (string) $input['template'] )
            : (string) $current['template'];

        if ( '' === trim( $template ) ) {
            $template = self::defaults()['template'];
        }

        $retention = isset( $input['retention_days'] )
            ? absint( $input['retention_days'] )
            : (int) $current['retention_days'];

        $retention = max( 1, min( 365, $retention ) );

        return [
            'token'           => $token,
            'channels'        => $channels,
            'default_mode'    => $mode,
            'template'        => $template,
            'category_filter' => isset( $input['category_filter'] )
                ? absint( $input['category_filter'] )
                : (int) $current['category_filter'],
            'retention_days'  => $retention,
        ];
    }

    public function sanitize_channels( $input, string $token = '' ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $catalog = [];
        $cache   = '' !== $token ? get_transient( self::catalog_key( $token ) ) : false;

        $current_channels = $this->get()['channels'];

        if ( is_array( $current_channels ) ) {
            foreach ( $current_channels as $existing ) {
                if ( isset( $existing['id'] ) ) {
                    $catalog[ (string) $existing['id'] ] = $existing;
                }
            }
        }

        if ( is_array( $cache ) ) {
            foreach ( $cache as $organization ) {
                foreach ( $organization['channels'] ?? [] as $channel ) {
                    if ( isset( $channel['id'] ) ) {
                        $catalog[ (string) $channel['id'] ] = $channel;
                    }
                }
            }
        }

        $channels = [];
        $seen     = [];

        foreach ( $input as $channel ) {
            if ( is_string( $channel ) ) {
                $id = substr( sanitize_text_field( $channel ), 0, 64 );
                $channel = $catalog[ $id ] ?? [ 'id' => $id, 'name' => '', 'service' => '' ];
            }

            if ( ! is_array( $channel ) ) {
                continue;
            }

            $id = isset( $channel['id'] )
                ? substr( sanitize_text_field( (string) $channel['id'] ), 0, 64 )
                : '';

            if ( '' === $id || isset( $seen[ $id ] ) ) {
                continue;
            }

            $seen[ $id ] = true;
            $channels[]  = [
                'id'      => $id,
                'name'    => isset( $channel['name'] )
                    ? substr( sanitize_text_field( (string) $channel['name'] ), 0, 191 )
                    : '',
                'service' => isset( $channel['service'] )
                    ? substr( sanitize_key( (string) $channel['service'] ), 0, 32 )
                    : '',
            ];
        }

        return $channels;
    }
}
