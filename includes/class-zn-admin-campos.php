<?php
/**
 * Piezas comunes de las fichas de wp-admin: sello de seguridad, fotos, coordenadas y avisos.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Admin_Campos {

	const NONCE = 'zn_guardar_ficha';

	/** Tipos de entrada que usan la caja de fotos (para cargar la biblioteca de medios). */
	private static array $tipos_con_fotos = array();

	public static function iniciar(): void {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'cargar_scripts' ) );
		add_action( 'admin_notices', array( __CLASS__, 'avisos' ) );
	}

	public static function usar_fotos( string $tipo ): void {
		self::$tipos_con_fotos[] = $tipo;
	}

	public static function cargar_scripts( string $pantalla ): void {
		$actual = get_current_screen();
		if ( ! $actual || ! in_array( $actual->post_type, self::$tipos_con_fotos, true ) || ! in_array( $pantalla, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'zn-admin-fotos', plugins_url( 'assets/admin-fotos.js', __DIR__ ), array( 'jquery' ), ZN_VERSION, true );
	}

	public static function valor( int $id, string $campo ): string {
		return (string) get_post_meta( $id, $campo, true );
	}

	public static function sello(): void {
		wp_nonce_field( self::NONCE, self::NONCE );
	}

	/** Lo enviado en zn[...] si el guardado es legítimo (sello válido, no autoguardado, con permiso); si no, null. */
	public static function datos_enviados( int $id ): ?array {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return null;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}
		return isset( $_POST['zn'] ) && is_array( $_POST['zn'] ) ? wp_unslash( $_POST['zn'] ) : array();
	}

	/** Guarda campo => valor (null = borrar) y deja los avisos para la siguiente pantalla. */
	public static function guardar( int $id, array $limpio, array $errores ): void {
		foreach ( $limpio as $campo => $valor ) {
			if ( null === $valor ) {
				delete_post_meta( $id, $campo );
			} else {
				update_post_meta( $id, $campo, $valor );
			}
		}
		if ( $errores ) {
			set_transient( 'zn_avisos_' . get_current_user_id(), $errores, 60 );
		}
	}

	/**
	 * Comprueba un valor del formulario con la regla del esquema común, la misma que aplicará la API (R58).
	 * Vacío → borrar (si es obligatorio, aviso y no se cambia). Si no vale, aviso y se conserva el anterior.
	 */
	public static function con_esquema( string $recurso, string $guardado, string $valor, array &$limpio, array &$errores ): void {
		$campo = ZN_Esquema::por_guardado( $recurso, $guardado );
		if ( '' === $valor && empty( $campo['obligatorio'] ) ) {
			$limpio[ $guardado ] = null;
			return;
		}
		$resultado = ZN_Esquema::comprobar( $campo, $valor, $campo['app'] );
		if ( isset( $resultado['errores'] ) ) {
			$errores[] = ZN_Esquema::aviso( $campo, $resultado );
			return;
		}
		$limpio = array_merge( $limpio, $resultado['valores'] );
	}

	/** Máximo de caracteres del campo en el esquema, para el atributo maxlength de la casilla. */
	public static function maximo( string $recurso, string $guardado ): string {
		$campo = ZN_Esquema::por_guardado( $recurso, $guardado );
		return isset( $campo['max'] ) ? sprintf( ' maxlength="%d"', (int) $campo['max'] ) : '';
	}

	/** El título lo guarda WordPress: si pasa del máximo de la API, solo se avisa (la app no podría guardarlo así). */
	public static function aviso_titulo( string $recurso, int $id, array &$errores ): void {
		$campo  = ZN_Esquema::por_guardado( $recurso, 'post_title' );
		$titulo = (string) get_post_field( 'post_title', $id, 'raw' );
		if ( isset( $campo['max'] ) && mb_strlen( $titulo ) > $campo['max'] ) {
			$errores[] = sprintf( '%s pasa de %d caracteres: la app no podría guardarlo así. Conviene acortarlo.', $campo['etiqueta'], $campo['max'] );
		}
	}

	/** Web o enlace escrito sin http(s)://: se le pone https:// antes de comprobarlo. */
	public static function con_protocolo( string $url ): string {
		return '' === $url || preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url ) ? $url : 'https://' . $url;
	}

	public static function texto( array $datos, string $clave ): string {
		return isset( $datos[ $clave ] ) && is_string( $datos[ $clave ] ) ? trim( $datos[ $clave ] ) : '';
	}

	/** Fila «Dueño» con las cuentas de la app de esos roles (R63). */
	public static function fila_dueno( int $id, array $roles_wp, string $ayuda ): void {
		$dueno = (int) self::valor( $id, 'user_id' );
		?>
		<tr>
			<th><label for="zn-dueno">Dueño</label></th>
			<td>
				<select id="zn-dueno" name="zn[user_id]">
					<option value="">— Sin dueño —</option>
					<?php foreach ( ZN_Roles::cuentas_de( $roles_wp ) as $cuenta ) : ?>
						<option value="<?php echo esc_attr( $cuenta->ID ); ?>" <?php selected( $dueno, (int) $cuenta->ID ); ?>><?php echo esc_html( "{$cuenta->display_name} ({$cuenta->user_email})" ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php echo esc_html( $ayuda ); ?></p>
			</td>
		</tr>
		<?php
	}

	/** El dueño solo puede ser una cuenta de la app con uno de esos roles (nunca una de wp-admin). */
	public static function limpiar_dueno( array $datos, array &$limpio, array &$errores, array $roles_wp ): void {
		$dueno = self::texto( $datos, 'user_id' );
		if ( '' === $dueno ) {
			$limpio['user_id'] = null;
			return;
		}
		$cuenta = ctype_digit( $dueno ) ? get_userdata( (int) $dueno ) : false;
		$rol    = $cuenta ? ZN_Roles::rol_app( $cuenta ) : null;
		if ( $rol && in_array( array_search( $rol, ZN_Roles::ROLES_APP, true ), $roles_wp, true ) ) {
			$limpio['user_id'] = (int) $dueno;
		} else {
			$errores[] = 'El dueño elegido no es una cuenta válida de la app; no se ha cambiado.';
		}
	}

	public static function fila_coordenadas( int $id ): void {
		?>
		<tr>
			<th>Ubicación en el mapa</th>
			<td>
				<label>Latitud <input type="text" inputmode="decimal" size="12" name="zn[latitude]" value="<?php echo esc_attr( self::valor( $id, 'latitude' ) ); ?>"></label>
				&nbsp;
				<label>Longitud <input type="text" inputmode="decimal" size="12" name="zn[longitude]" value="<?php echo esc_attr( self::valor( $id, 'longitude' ) ); ?>"></label>
				<p class="description">En Google Maps: clic derecho sobre el sitio y pulsa los números que aparecen arriba (se copian los dos). Ejemplo: 42.2388906, -8.7259422</p>
			</td>
		</tr>
		<?php
	}

	/** Latitud y longitud con la regla del esquema; se admite la coma decimal. */
	public static function limpiar_coordenadas( string $recurso, array $datos, array &$limpio, array &$errores ): void {
		foreach ( array( 'latitude', 'longitude' ) as $campo ) {
			self::con_esquema( $recurso, $campo, str_replace( ',', '.', self::texto( $datos, $campo ) ), $limpio, $errores );
		}
	}

	/** Filas «Empieza» y «Termina» con calendario. Las fechas se guardan como "Y-m-d H:i:s" (hora de Madrid). */
	public static function filas_fechas( int $id, string $nota ): void {
		$casilla = static function ( string $fecha ): string {
			return $fecha ? str_replace( ' ', 'T', substr( $fecha, 0, 16 ) ) : '';
		};
		?>
		<tr>
			<th><label for="zn-inicio">Empieza</label></th>
			<td><input type="datetime-local" id="zn-inicio" name="zn[start_date]" value="<?php echo esc_attr( $casilla( self::valor( $id, 'start_date' ) ) ); ?>"></td>
		</tr>
		<tr>
			<th><label for="zn-fin">Termina</label></th>
			<td>
				<input type="datetime-local" id="zn-fin" name="zn[end_date]" value="<?php echo esc_attr( $casilla( self::valor( $id, 'end_date' ) ) ); ?>">
				<p class="description"><?php echo esc_html( $nota ); ?></p>
			</td>
		</tr>
		<?php
	}

	/** start_date y end_date desde las casillas de calendario ("2026-11-02T10:30"). El fin no puede ir antes del inicio. */
	public static function limpiar_fechas( array $datos, array &$limpio, array &$errores ): void {
		foreach ( array( 'start_date' => 'inicio', 'end_date' => 'fin' ) as $campo => $nombre ) {
			$valor = self::texto( $datos, $campo );
			if ( '' === $valor ) {
				$limpio[ $campo ] = null;
				continue;
			}
			$fecha = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $valor );
			if ( $fecha && $fecha->format( 'Y-m-d\TH:i' ) === $valor ) {
				$limpio[ $campo ] = $fecha->format( 'Y-m-d H:i:s' );
			} else {
				$errores[] = "La fecha de $nombre no es válida; no se ha cambiado.";
			}
		}
		if ( ! empty( $limpio['start_date'] ) && ! empty( $limpio['end_date'] ) && $limpio['end_date'] < $limpio['start_date'] ) {
			unset( $limpio['end_date'] );
			$errores[] = 'La fecha de fin es anterior a la de inicio; no se ha cambiado.';
		}
	}

	public static function caja_fotos( WP_Post $entrada ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$url = self::valor( $entrada->ID, "photo_{$i}" );
			?>
			<div class="zn-foto" style="display:inline-block;vertical-align:top;width:200px;margin:0 16px 16px 0">
				<img src="<?php echo esc_url( $url ); ?>" alt="" style="width:200px;height:140px;object-fit:cover;background:#f0f0f1;<?php echo $url ? '' : 'display:none;'; ?>">
				<input type="hidden" name="zn[photo_<?php echo (int) $i; ?>]" value="<?php echo esc_attr( $url ); ?>">
				<p>
					<button type="button" class="button zn-elegir-foto">Elegir foto <?php echo (int) $i + 1; ?></button>
					<button type="button" class="button-link zn-quitar-foto" <?php echo $url ? '' : 'style="display:none"'; ?>>Quitar</button>
				</p>
			</div>
			<?php
		}
	}

	public static function caja_videos( WP_Post $entrada ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			printf(
				'<p><label>Vídeo %1$d <input type="url" class="large-text" name="zn[video_%2$d]" placeholder="https://" value="%3$s"></label></p>',
				(int) $i + 1,
				(int) $i,
				esc_attr( self::valor( $entrada->ID, "video_{$i}" ) )
			);
		}
		echo '<p class="description">Dirección completa de un vídeo (por ejemplo, un .mp4).</p>';
	}

	/** Fotos y vídeos: solo direcciones http(s); cualquier otra cosa se descarta. */
	public static function limpiar_urls( array $datos, string $prefijo, array &$limpio ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			$url                        = esc_url_raw( self::texto( $datos, "{$prefijo}_{$i}" ), array( 'http', 'https' ) );
			$limpio[ "{$prefijo}_{$i}" ] = '' === $url ? null : $url;
		}
	}

	public static function avisos(): void {
		$clave   = 'zn_avisos_' . get_current_user_id();
		$errores = get_transient( $clave );
		if ( ! $errores ) {
			return;
		}
		delete_transient( $clave );
		echo '<div class="notice notice-warning is-dismissible"><p>' . implode( '<br>', array_map( 'esc_html', $errores ) ) . '</p></div>';
	}
}
