<?php
/**
 * Ficha de la novedad (evento, promoción o noticia) en wp-admin: casillas para los campos extra del tipo `evento`.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Admin_Eventos {

	public static function iniciar(): void {
		ZN_Admin_Campos::usar_fotos( ZN_Eventos::TIPO );
		add_action( 'add_meta_boxes_' . ZN_Eventos::TIPO, array( __CLASS__, 'anadir_cajas' ) );
		add_action( 'save_post_' . ZN_Eventos::TIPO, array( __CLASS__, 'guardar' ) );
	}

	public static function anadir_cajas(): void {
		add_meta_box( 'zn-datos-evento', 'Datos de la novedad', array( __CLASS__, 'caja_datos' ), ZN_Eventos::TIPO, 'normal', 'high' );
		add_meta_box( 'zn-fotos-evento', 'Fotos (hasta 3)', array( 'ZN_Admin_Campos', 'caja_fotos' ), ZN_Eventos::TIPO, 'normal', 'default' );
		add_meta_box( 'zn-videos-evento', 'Vídeos (opcional)', array( __CLASS__, 'caja_videos' ), ZN_Eventos::TIPO, 'normal', 'low' );
	}

	/** "2024-02-15 19:00:00" → "2024-02-15T19:00" para la casilla de fecha y hora. */
	private static function para_casilla( string $fecha ): string {
		return $fecha ? str_replace( ' ', 'T', substr( $fecha, 0, 16 ) ) : '';
	}

	public static function caja_datos( WP_Post $entrada ): void {
		ZN_Admin_Campos::sello();
		$id        = $entrada->ID;
		$categoria = (int) ZN_Admin_Campos::valor( $id, 'event_category_id' );
		$negocio   = (int) ZN_Admin_Campos::valor( $id, 'business_id' );
		$negocios  = get_posts(
			array(
				'post_type'   => ZN_Negocios::TIPO,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'title',
				'order'       => 'ASC',
			)
		);
		?>
		<p class="description">El título va arriba y el texto en el cuadro grande.</p>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="zn-tipo">Tipo</label></th>
				<td>
					<select id="zn-tipo" name="zn[event_category_id]">
						<option value="">— Sin tipo —</option>
						<?php foreach ( ZN_Eventos::CATEGORIAS as $valor => $nombre ) : ?>
							<option value="<?php echo esc_attr( $valor ); ?>" <?php selected( $categoria, $valor ); ?>><?php echo esc_html( $nombre ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Decide el filtro de la pestaña Novedades de la app (Eventos, Promociones o Noticias).</p>
				</td>
			</tr>
			<tr>
				<th><label for="zn-negocio">Negocio</label></th>
				<td>
					<select id="zn-negocio" name="zn[business_id]">
						<option value="">— Ninguno (de la asociación) —</option>
						<?php foreach ( $negocios as $opcion ) : ?>
							<option value="<?php echo esc_attr( $opcion->ID ); ?>" <?php selected( $negocio, $opcion->ID ); ?>><?php echo esc_html( $opcion->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Si es de un negocio, sale también en la ficha de ese negocio.</p>
				</td>
			</tr>
			<tr>
				<th><label for="zn-inicio">Empieza</label></th>
				<td><input type="datetime-local" id="zn-inicio" name="zn[start_date]" value="<?php echo esc_attr( self::para_casilla( ZN_Admin_Campos::valor( $id, 'start_date' ) ) ); ?>"></td>
			</tr>
			<tr>
				<th><label for="zn-fin">Termina</label></th>
				<td>
					<input type="datetime-local" id="zn-fin" name="zn[end_date]" value="<?php echo esc_attr( self::para_casilla( ZN_Admin_Campos::valor( $id, 'end_date' ) ) ); ?>">
					<p class="description">Las dos fechas son opcionales. Hora de España.</p>
				</td>
			</tr>
			<?php ZN_Admin_Campos::fila_coordenadas( $id ); ?>
		</table>
		<?php
	}

	public static function caja_videos( WP_Post $entrada ): void {
		for ( $i = 0; $i < 3; $i++ ) {
			printf(
				'<p><label>Vídeo %1$d <input type="url" class="large-text" name="zn[video_%2$d]" placeholder="https://" value="%3$s"></label></p>',
				(int) $i + 1,
				(int) $i,
				esc_attr( ZN_Admin_Campos::valor( $entrada->ID, "video_{$i}" ) )
			);
		}
		echo '<p class="description">Dirección completa de un vídeo (por ejemplo, un .mp4).</p>';
	}

	public static function guardar( int $id ): void {
		$datos = ZN_Admin_Campos::datos_enviados( $id );
		if ( null === $datos ) {
			return;
		}
		$errores = array();
		ZN_Admin_Campos::guardar( $id, self::limpiar( $datos, $errores ), $errores );
	}

	/**
	 * Valida y limpia lo que llega del formulario. Devuelve campo => valor (null = borrar).
	 * Un valor no válido no se guarda (se conserva el anterior) y se añade a $errores.
	 */
	public static function limpiar( array $datos, array &$errores ): array {
		$limpio = array();
		$texto  = static fn( $clave ) => ZN_Admin_Campos::texto( $datos, $clave );

		$categoria = $texto( 'event_category_id' );
		if ( '' === $categoria ) {
			$limpio['event_category_id'] = null;
		} elseif ( isset( ZN_Eventos::CATEGORIAS[ (int) $categoria ] ) ) {
			$limpio['event_category_id'] = (int) $categoria;
		}

		$negocio = $texto( 'business_id' );
		if ( '' === $negocio ) {
			$limpio['business_id'] = null;
		} elseif ( ZN_API::entrada( (int) $negocio, ZN_Negocios::TIPO ) ) {
			$limpio['business_id'] = (int) $negocio;
		} else {
			$errores[] = 'El negocio elegido no existe o no está publicado; no se ha cambiado.';
		}

		foreach ( array( 'start_date' => 'inicio', 'end_date' => 'fin' ) as $campo => $nombre ) {
			$valor = $texto( $campo );
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

		ZN_Admin_Campos::limpiar_coordenadas( $datos, $limpio, $errores );
		ZN_Admin_Campos::limpiar_urls( $datos, 'photo', $limpio );
		ZN_Admin_Campos::limpiar_urls( $datos, 'video', $limpio );

		return $limpio;
	}
}
