<?php
/**
 * Ficha del negocio en wp-admin: casillas para los campos extra del tipo `negocio`.
 * La puntuación no se edita aquí: la calcularán las reseñas (H14 del Laravel).
 */

defined( 'ABSPATH' ) || exit;

class ZN_Admin_Negocios {

	// Casillas de texto de una línea: campo => etiqueta.
	const TEXTOS = array(
		'address'  => 'Dirección',
		'postcode' => 'Código postal',
		'city'     => 'Ciudad',
		'state'    => 'Provincia',
		'country'  => 'País (2 letras, p. ej. ES)',
		'phone'    => 'Teléfono',
	);

	public static function iniciar(): void {
		ZN_Admin_Campos::usar_fotos( ZN_Negocios::TIPO );
		add_action( 'add_meta_boxes_' . ZN_Negocios::TIPO, array( __CLASS__, 'anadir_cajas' ) );
		add_action( 'save_post_' . ZN_Negocios::TIPO, array( __CLASS__, 'guardar' ) );
	}

	public static function anadir_cajas(): void {
		add_meta_box( 'zn-datos-negocio', 'Datos del negocio', array( __CLASS__, 'caja_datos' ), ZN_Negocios::TIPO, 'normal', 'high' );
		add_meta_box( 'zn-fotos-negocio', 'Fotos (hasta 3)', array( 'ZN_Admin_Campos', 'caja_fotos' ), ZN_Negocios::TIPO, 'normal', 'default' );
		add_meta_box( 'zn-puntuacion-negocio', 'Puntuación', array( __CLASS__, 'caja_puntuacion' ), ZN_Negocios::TIPO, 'side', 'default' );
	}

	public static function caja_datos( WP_Post $entrada ): void {
		ZN_Admin_Campos::sello();
		$id        = $entrada->ID;
		$categoria = (int) ZN_Admin_Campos::valor( $id, 'business_category_id' );
		$dueno     = ZN_Negocios::dueno( $id );
		?>
		<p class="description">El nombre va en el título y la descripción en el cuadro grande de arriba.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="zn-categoria">Categoría</label></th>
				<td>
					<select id="zn-categoria" name="zn[business_category_id]">
						<option value="">— Sin categoría —</option>
						<?php foreach ( ZN_Negocios::CATEGORIAS as $valor => $nombre ) : ?>
							<option value="<?php echo esc_attr( $valor ); ?>" <?php selected( $categoria, $valor ); ?>><?php echo esc_html( $nombre ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="zn-dueno">Dueño</label></th>
				<td>
					<select id="zn-dueno" name="zn[user_id]">
						<option value="">— Sin dueño —</option>
						<?php foreach ( ZN_Roles::cuentas( 'zn_comercio' ) as $cuenta ) : ?>
							<option value="<?php echo esc_attr( $cuenta->ID ); ?>" <?php selected( $dueno, (int) $cuenta->ID ); ?>><?php echo esc_html( "{$cuenta->display_name} ({$cuenta->user_email})" ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Solo cuentas con el rol «Comercio (app)». Las preguntas de la app van a este comercio.</p>
				</td>
			</tr>
			<?php foreach ( self::TEXTOS as $campo => $etiqueta ) : ?>
				<tr>
					<th><label for="zn-<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $etiqueta ); ?></label></th>
					<td><input type="text" class="regular-text" id="zn-<?php echo esc_attr( $campo ); ?>" name="zn[<?php echo esc_attr( $campo ); ?>]"<?php echo ZN_Admin_Campos::maximo( ZN_Negocios::TIPO, $campo ); // phpcs:ignore WordPress.Security.EscapeOutput ?> value="<?php echo esc_attr( ZN_Admin_Campos::valor( $id, $campo ) ); ?>"></td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th><label for="zn-email">Email de contacto</label></th>
				<td><input type="email" class="regular-text" id="zn-email" name="zn[email]" maxlength="50" value="<?php echo esc_attr( ZN_Admin_Campos::valor( $id, 'email' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-web">Web</label></th>
				<td><input type="url" class="regular-text" id="zn-web" name="zn[web]" maxlength="255" placeholder="https://" value="<?php echo esc_attr( ZN_Admin_Campos::valor( $id, 'web' ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-schedule">Horario</label></th>
				<td>
					<textarea class="large-text" rows="7" id="zn-schedule" name="zn[schedule]"><?php echo esc_textarea( ZN_Admin_Campos::valor( $id, 'schedule' ) ); ?></textarea>
					<p class="description">Un día por línea, por ejemplo: «lunes: 10:00–14:00, 16:30–20:30».</p>
				</td>
			</tr>
			<?php ZN_Admin_Campos::fila_coordenadas( $id ); ?>
		</table>
		<?php
	}

	public static function caja_puntuacion( WP_Post $entrada ): void {
		$puntos = ZN_Admin_Campos::valor( $entrada->ID, 'score' );
		printf(
			'<p><strong>%s</strong> de 5</p><p class="description">Se calcula sola con las reseñas; no se puede cambiar a mano.</p>',
			esc_html( '' === $puntos ? '0' : $puntos )
		);
	}

	public static function guardar( int $id ): void {
		$datos = ZN_Admin_Campos::datos_enviados( $id );
		if ( null === $datos ) {
			return;
		}
		$errores = array();
		$limpio  = self::limpiar( $datos, $errores );
		ZN_Admin_Campos::aviso_titulo( ZN_Negocios::TIPO, $id, $errores );
		ZN_Admin_Campos::guardar( $id, $limpio, $errores );
	}

	/**
	 * Valida y limpia lo que llega del formulario con las mismas reglas que la API (ZN_Esquema, R58).
	 * Devuelve campo => valor (null = borrar). Un valor no válido no se guarda (se conserva el anterior)
	 * y se añade a $errores.
	 */
	public static function limpiar( array $datos, array &$errores ): array {
		$limpio = array();
		$texto  = static fn( $clave ) => ZN_Admin_Campos::texto( $datos, $clave );
		$regla  = static function ( string $campo, string $valor ) use ( &$limpio, &$errores ) {
			ZN_Admin_Campos::con_esquema( ZN_Negocios::TIPO, $campo, $valor, $limpio, $errores );
		};

		foreach ( array_keys( self::TEXTOS ) as $campo ) {
			$regla( $campo, $texto( $campo ) );
		}
		$regla( 'business_category_id', $texto( 'business_category_id' ) );

		// El dueño solo puede ser una cuenta Comercio de la app, nunca una de wp-admin (R1).
		$dueno = $texto( 'user_id' );
		if ( '' === $dueno ) {
			$limpio['user_id'] = null;
		} elseif ( self::es_comercio( (int) $dueno ) ) {
			$limpio['user_id'] = (int) $dueno;
		} else {
			$errores[] = 'El dueño elegido no es una cuenta de comercio; no se ha cambiado.';
		}

		$regla( 'email', $texto( 'email' ) );
		$regla( 'web', ZN_Admin_Campos::con_protocolo( $texto( 'web' ) ) );
		$regla( 'schedule', $texto( 'schedule' ) );

		ZN_Admin_Campos::limpiar_coordenadas( ZN_Negocios::TIPO, $datos, $limpio, $errores );
		ZN_Admin_Campos::limpiar_urls( $datos, 'photo', $limpio );

		return $limpio;
	}

	private static function es_comercio( int $id ): bool {
		$usuario = $id ? get_userdata( $id ) : false;
		return $usuario && 'commerce' === ZN_Roles::rol_app( $usuario );
	}
}
