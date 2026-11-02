<?php
/**
 * Una sola definición de los campos de cada recurso (R58), que usan:
 * - el registro de los campos personalizados (ZN_Negocios, ZN_Eventos, ZN_Sorteos);
 * - los formateadores de la API (los campos «simples» de la respuesta);
 * - las fichas de wp-admin (mismas reglas y máximos que la API);
 * - la orden de datos de prueba;
 * - y las rutas de escritura, con validar(), que devuelve los errores con los textos del Laravel.
 *
 * Cada campo: nombre en la app (camelCase, como lo manda) => [
 *   'guardado'    => campo personalizado, o post_title / post_content;
 *   'tipo'        => texto, parrafos, escrito, entero, numero, booleano, email, url, pais, fecha, urls, opciones,
 *                    cuenta (una cuenta de la app) o negocio (un negocio publicado);
 *   'max'         => caracteres como máximo (los de la sección 4.3 del traspaso; urls: elementos);
 *   'obligatorio' => al crear (y no se puede borrar después);
 *   'salida'      => nombre en la respuesta de la API, si sale tal cual;
 *   'etiqueta'    => nombre en los avisos de wp-admin; y min/entre/opciones/alias según el tipo ].
 *
 * Regla del PATCH (decisión a de Luis, por H22): campo ausente → no se toca; null → se borra (si es
 * obligatorio, 422); "" o solo espacios → 422. Los campos que no están en el esquema se ignoran (como el Laravel).
 *
 * Los campos de dueño ('dueno' => true) no se tratan como uno más (R79): después de validar(), la ruta llama a
 * permitir_dueno(), que aplica los permisos. Y guarda con guardar() (R80), que reparte cada valor a su sitio.
 */

defined( 'ABSPATH' ) || exit;

class ZN_Esquema {

	// Mensajes de validación del Laravel 10 (lang/en/validation.php), con :attribute como lo pinta el Laravel.
	const MENSAJES = array(
		'required' => 'The :attribute field is required.',
		'filled'   => 'The :attribute field must have a value.',
		'string'   => 'The :attribute field must be a string.',
		'max'      => 'The :attribute field must not be greater than :max characters.',
		'max_array' => 'The :attribute field must not have more than :max items.',
		'array'    => 'The :attribute field must be an array.',
		'integer'  => 'The :attribute field must be an integer.',
		'numeric'  => 'The :attribute field must be a number.',
		'min'      => 'The :attribute field must be at least :min.',
		'between'  => 'The :attribute field must be between :min and :max.',
		'boolean'  => 'The :attribute field must be true or false.',
		'email'    => 'The :attribute field must be a valid email address.',
		'url'      => 'The :attribute field must be a valid URL.',
		'size'     => 'The :attribute field must be :size characters.',
		'alpha'    => 'The :attribute field must only contain letters.',
		'date_format' => 'The :attribute field must match the format :format.',
		'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
		'exists'   => 'The selected :attribute is invalid.',
		'unique'   => 'The :attribute has already been taken.',
		'min_string' => 'The :attribute field must be at least :min characters.',
		'digits'   => 'The :attribute field must be :digits digits.',
		'regex'    => 'The :attribute field format is invalid.',
	);

	// Lo mismo para los avisos de wp-admin («La web no es válida; no se ha cambiado.»).
	const AVISOS = array(
		'required' => 'es obligatorio',
		'filled'   => 'no puede quedar vacío',
		'string'   => 'no es un texto',
		'max'      => 'pasa de :max caracteres',
		'max_array' => 'tiene más de :max elementos',
		'array'    => 'no es una lista',
		'integer'  => 'no es un número entero',
		'numeric'  => 'no es un número',
		'min'      => 'tiene que ser :min o más',
		'between'  => 'tiene que estar entre :min y :max',
		'boolean'  => 'tiene que ser sí o no',
		'email'    => 'no es un email válido',
		'url'      => 'no es una dirección web válida',
		'size'     => 'tiene que tener :size letras',
		'alpha'    => 'solo puede llevar letras',
		'date_format' => 'no es una fecha válida',
		'after_or_equal' => 'es anterior a la de inicio',
		'exists'   => 'no existe o no se puede elegir',
		'unique'   => 'ya está en uso',
		'min_string' => 'tiene que tener al menos :min caracteres',
		'digits'   => 'tiene que tener :digits cifras',
		'regex'    => 'no tiene un formato válido',
	);

	// Permiso del Laravel para guardar cada recurso con dueño (R79).
	const PERMISOS = array(
		'negocio' => 'business:save',
		'evento'  => 'event:save',
		'sorteo'  => 'draw:save',
	);

	const FORMATO_FECHA = 'Y-m-d H:i:s';

	/** Campos de cada recurso. Las claves de primer nivel son los tipos de entrada (o de comentario). */
	public static function campos( string $recurso ): array {
		$fotos  = array( 'guardado' => 'photo', 'tipo' => 'urls', 'max' => 3, 'salida' => 'photo', 'etiqueta' => 'La foto' );
		$videos = array( 'guardado' => 'video', 'tipo' => 'urls', 'max' => 3, 'salida' => 'video', 'etiqueta' => 'El vídeo' );
		$lat    = array( 'guardado' => 'latitude', 'tipo' => 'numero', 'entre' => array( -90, 90 ), 'decimales' => 7, 'salida' => 'latitude', 'etiqueta' => 'La latitud' );
		$lon    = array( 'guardado' => 'longitude', 'tipo' => 'numero', 'entre' => array( -180, 180 ), 'decimales' => 7, 'salida' => 'longitude', 'etiqueta' => 'La longitud' );

		switch ( $recurso ) {
			case 'negocio':
				return array(
					'name'        => array( 'guardado' => 'post_title', 'tipo' => 'texto', 'max' => 50, 'obligatorio' => true, 'salida' => 'name', 'etiqueta' => 'El nombre' ),
					'description' => array( 'guardado' => 'post_content', 'tipo' => 'parrafos', 'salida' => 'description', 'etiqueta' => 'La descripción' ),
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'dueno' => true, 'etiqueta' => 'El dueño' ),
					'categoryId'  => array( 'guardado' => 'business_category_id', 'tipo' => 'opciones', 'opciones' => array_keys( ZN_Negocios::CATEGORIAS ), 'etiqueta' => 'La categoría' ),
					'latitude'    => $lat,
					'longitude'   => $lon,
					'address'     => array( 'guardado' => 'address', 'tipo' => 'texto', 'max' => 100, 'salida' => 'address', 'etiqueta' => 'La dirección' ),
					'postcode'    => array( 'guardado' => 'postcode', 'tipo' => 'texto', 'max' => 10, 'salida' => 'postcode', 'etiqueta' => 'El código postal' ),
					'state'       => array( 'guardado' => 'state', 'tipo' => 'texto', 'max' => 30, 'salida' => 'state', 'etiqueta' => 'La provincia' ),
					'city'        => array( 'guardado' => 'city', 'tipo' => 'texto', 'max' => 30, 'salida' => 'city', 'etiqueta' => 'La ciudad' ),
					'country'     => array( 'guardado' => 'country', 'tipo' => 'pais', 'max' => 2, 'salida' => 'country', 'etiqueta' => 'El país' ),
					'phone'       => array( 'guardado' => 'phone', 'tipo' => 'texto', 'max' => 20, 'salida' => 'phone', 'etiqueta' => 'El teléfono' ),
					'email'       => array( 'guardado' => 'email', 'tipo' => 'email', 'max' => 50, 'salida' => 'email', 'etiqueta' => 'El email' ),
					'web'         => array( 'guardado' => 'web', 'tipo' => 'url', 'max' => 255, 'salida' => 'web', 'etiqueta' => 'La web' ),
					'schedule'    => array( 'guardado' => 'schedule', 'tipo' => 'parrafos', 'salida' => 'schedule', 'etiqueta' => 'El horario' ),
					'photos'      => $fotos,
					'videos'      => $videos,
				);

			case 'evento':
				return array(
					'name'        => array( 'guardado' => 'post_title', 'tipo' => 'texto', 'max' => 255, 'obligatorio' => true, 'salida' => 'name', 'etiqueta' => 'El título' ),
					'description' => array( 'guardado' => 'post_content', 'tipo' => 'parrafos', 'salida' => 'description', 'etiqueta' => 'El texto' ),
					'latitude'    => $lat,
					'longitude'   => $lon,
					'photos'      => $fotos,
					'videos'      => $videos,
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'dueno' => true, 'etiqueta' => 'El dueño' ),
					'businessId'  => array( 'guardado' => 'business_id', 'tipo' => 'negocio', 'etiqueta' => 'El negocio' ),
					// La app manda `category` y, al editar, también `categoryId` con el mismo valor (H37).
					'category'    => array( 'guardado' => 'event_category_id', 'tipo' => 'opciones', 'opciones' => array_keys( ZN_Eventos::CATEGORIAS ), 'alias' => 'categoryId', 'etiqueta' => 'El tipo' ),
					'startDate'   => array( 'guardado' => 'start_date', 'tipo' => 'fecha', 'etiqueta' => 'La fecha de inicio' ),
					'endDate'     => array( 'guardado' => 'end_date', 'tipo' => 'fecha', 'despues_de' => 'startDate', 'etiqueta' => 'La fecha de fin' ),
				);

			case 'sorteo':
				return array(
					'title'       => array( 'guardado' => 'post_title', 'tipo' => 'texto', 'max' => 50, 'obligatorio' => true, 'salida' => 'title', 'etiqueta' => 'El título' ),
					'description' => array( 'guardado' => 'post_content', 'tipo' => 'parrafos', 'salida' => 'description', 'etiqueta' => 'Las condiciones' ),
					'startDate'   => array( 'guardado' => 'start_date', 'tipo' => 'fecha', 'obligatorio' => true, 'salida' => 'startDate', 'etiqueta' => 'La fecha de inicio' ),
					'endDate'     => array( 'guardado' => 'end_date', 'tipo' => 'fecha', 'obligatorio' => true, 'despues_de' => 'startDate', 'salida' => 'endDate', 'etiqueta' => 'La fecha de fin' ),
					'winners'     => array( 'guardado' => 'winners', 'tipo' => 'entero', 'min' => 1, 'obligatorio' => true, 'salida' => 'winners', 'etiqueta' => 'El número de ganadores' ),
					'photos'      => $fotos,
					'videos'      => $videos,
					'legalUrl'    => array( 'guardado' => 'legal_url', 'tipo' => 'url', 'max' => 255, 'salida' => 'legalUrl', 'etiqueta' => 'La dirección de las bases' ),
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'dueno' => true, 'etiqueta' => 'El dueño' ),
				);

			// Reseñas y preguntas son comentarios: el autor sale siempre del token, nunca del cuerpo (H21).
			case ZN_Comentarios::RESENA:
				return array(
					'businessId'  => array( 'guardado' => 'comment_post_ID', 'tipo' => 'negocio', 'obligatorio' => true, 'etiqueta' => 'El negocio' ),
					// El Laravel no limita la puntuación (H13); aquí, de 1 a 5 como la app.
					'score'       => array( 'guardado' => 'score', 'tipo' => 'numero', 'entre' => array( 1, 5 ), 'obligatorio' => true, 'etiqueta' => 'La puntuación' ),
					'title'       => array( 'guardado' => 'title', 'tipo' => 'escrito', 'max' => 50, 'etiqueta' => 'El título' ),
					'description' => array( 'guardado' => 'comment_content', 'tipo' => 'escrito', 'max' => 2000, 'etiqueta' => 'El texto' ),
					// Solo por PATCH /review/reply (el dueño del negocio); POST /review la quita antes de validar.
					'response'    => array( 'guardado' => 'response', 'tipo' => 'escrito', 'max' => 2000, 'etiqueta' => 'La respuesta' ),
				);

			case ZN_Comentarios::PREGUNTA:
				return array(
					// El comercio destinatario (que sea un comercio lo comprueba la ruta, como el Laravel).
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'etiqueta' => 'El comercio' ),
					'question'    => array( 'guardado' => 'comment_content', 'tipo' => 'escrito', 'max' => 2000, 'obligatorio' => true, 'etiqueta' => 'La pregunta' ),
					'answer'      => array( 'guardado' => 'answer', 'tipo' => 'escrito', 'max' => 2000, 'etiqueta' => 'La respuesta' ),
					'published'   => array( 'guardado' => 'comment_approved', 'tipo' => 'booleano', 'etiqueta' => 'Publicada' ),
				);

			// Cuenta (registro, PATCH /user, recuperación): nunca más estricto que la app (src/features/users/validacion.ts).
			case 'cuenta':
				return array(
					// El nombre completo va en first_name (R53).
					'name'      => array( 'guardado' => 'first_name', 'tipo' => 'texto', 'max' => 255, 'obligatorio' => true, 'etiqueta' => 'El nombre' ),
					// La columna user_email de WordPress es de 100.
					'email'     => array( 'guardado' => 'user_email', 'tipo' => 'email', 'max' => 100, 'obligatorio' => true, 'unico' => true, 'etiqueta' => 'El email' ),
					'password'  => array( 'guardado' => 'user_pass', 'tipo' => 'contrasena', 'min' => 8, 'max' => 255, 'obligatorio' => true, 'etiqueta' => 'La contraseña' ),
					'phone'     => array( 'guardado' => 'phone', 'tipo' => 'telefono', 'max' => 20, 'etiqueta' => 'El teléfono' ),
					'latitude'  => $lat,
					'longitude' => $lon,
					// Código de 6 cifras de la recuperación (obligatorio solo en esa ruta: lo exige ella).
					'code'      => array( 'guardado' => 'code', 'tipo' => 'codigo', 'etiqueta' => 'El código' ),
				);
		}
		return array();
	}

	/** Campos guardados aparte que no se escriben desde la app (los calcula el plugin). */
	const CALCULADOS = array(
		'negocio' => array( 'score' => 'number' ),
	);

	/** Campos personalizados del tipo y su tipo para register_post_meta (fotos y vídeos, uno por hueco). */
	public static function metas( string $recurso ): array {
		$metas = array();
		foreach ( self::campos( $recurso ) as $campo ) {
			if ( 0 === strpos( $campo['guardado'], 'post_' ) ) {
				continue;
			}
			if ( 'urls' === $campo['tipo'] ) {
				for ( $i = 0; $i < $campo['max']; $i++ ) {
					$metas[ "{$campo['guardado']}_{$i}" ] = 'string';
				}
				continue;
			}
			$metas[ $campo['guardado'] ] = self::tipo_meta( $campo['tipo'] );
		}
		return $metas + ( self::CALCULADOS[ $recurso ] ?? array() );
	}

	/** Un campo por su nombre guardado (p. ej. 'country'), para las fichas de wp-admin. */
	public static function por_guardado( string $recurso, string $guardado ): ?array {
		foreach ( self::campos( $recurso ) as $app => $campo ) {
			if ( $campo['guardado'] === $guardado ) {
				return $campo + array( 'app' => $app );
			}
		}
		return null;
	}

	/** Campos de la respuesta que salen tal cual de lo guardado, en el orden del esquema. */
	public static function salida( string $recurso, WP_Post $entrada ): array {
		$datos = array();
		foreach ( self::campos( $recurso ) as $campo ) {
			if ( ! isset( $campo['salida'] ) ) {
				continue;
			}
			$clave = $campo['salida'];
			switch ( $campo['guardado'] ) {
				case 'post_title':
					$datos[ $clave ] = $entrada->post_title;
					continue 2;
				case 'post_content':
					$datos[ $clave ] = ZN_API::texto_plano( $entrada->post_content );
					continue 2;
			}
			$valor = ZN_API::campo( $entrada->ID, $campo['guardado'] );
			switch ( $campo['tipo'] ) {
				case 'urls':
					for ( $i = 0; $i < $campo['max']; $i++ ) {
						$datos[ "{$clave}_{$i}" ] = ZN_API::campo( $entrada->ID, "{$campo['guardado']}_{$i}" );
					}
					break;
				case 'numero':
					$datos[ $clave ] = ZN_API::numero( $valor );
					break;
				case 'entero':
					$datos[ $clave ] = (int) $valor;
					break;
				default:
					$datos[ $clave ] = $valor;
			}
		}
		return $datos;
	}

	/**
	 * Valida lo que manda la app. $actual = null al crear (POST); al modificar (PATCH), los valores guardados
	 * (nombre guardado => valor) para las comprobaciones entre campos (el fin no antes del inicio).
	 * Devuelve ['valores' => nombre guardado => valor (null = borrar; fotos: photo_0…), 'errores' => campo => [mensajes]].
	 */
	public static function validar( string $recurso, array $datos, ?array $actual = null ): array {
		$campos  = self::campos( $recurso );
		$crear   = null === $actual;
		$valores = array();
		$errores = array();

		foreach ( $campos as $app => $campo ) {
			$clave = array_key_exists( $app, $datos ) ? $app : ( isset( $campo['alias'] ) && array_key_exists( $campo['alias'], $datos ) ? $campo['alias'] : null );
			if ( null === $clave ) {
				if ( $crear && ! empty( $campo['obligatorio'] ) ) {
					$errores[ $app ][] = self::mensaje( 'required', $app );
				}
				continue;
			}
			$resultado = self::comprobar( $campo, $datos[ $clave ], $app );
			if ( isset( $resultado['errores'] ) ) {
				$errores += $resultado['errores'];
				continue;
			}
			$valores += $resultado['valores'];
		}

		// Únicos (el email de la cuenta): sin contar la propia cuenta al modificarla. Es el único error que la app
		// reconoce como «email ya registrado» (src/api/errores.ts).
		foreach ( $campos as $app => $campo ) {
			if ( empty( $campo['unico'] ) || isset( $errores[ $app ] ) || empty( $valores[ $campo['guardado'] ] ) ) {
				continue;
			}
			$otra = email_exists( $valores[ $campo['guardado'] ] );
			if ( $otra && (int) $otra !== (int) ( ( $actual ?? array() )['ID'] ?? 0 ) ) {
				$errores[ $app ][] = self::mensaje( 'unique', $app );
			}
		}

		// El fin no puede ir antes del inicio (el Laravel solo lo tenía en un comentario).
		foreach ( $campos as $app => $campo ) {
			if ( empty( $campo['despues_de'] ) || isset( $errores[ $app ] ) || empty( $valores[ $campo['guardado'] ] ) ) {
				continue;
			}
			$otro   = $campos[ $campo['despues_de'] ];
			$inicio = array_key_exists( $otro['guardado'], $valores ) ? $valores[ $otro['guardado'] ] : ( ( $actual ?? array() )[ $otro['guardado'] ] ?? null );
			if ( $inicio && $valores[ $campo['guardado'] ] < $inicio ) {
				$errores[ $app ][] = self::mensaje( 'after_or_equal', $app, array( ':date' => self::atributo( $campo['despues_de'] ) ) );
			}
		}

		return array( 'valores' => $valores, 'errores' => $errores );
	}

	/**
	 * Campos de dueño tras validar() (R79), con los permisos de la cuenta $usuario (por defecto, la de la sesión):
	 * - al crear ($elemento null): el dueño propuesto tiene que pasar ZN_Roles::puede_crear(); si no, 'sin_permiso'
	 *   (la ruta responde 401 «This action is unauthorized.», como el Laravel);
	 * - al modificar: el dueño solo cambia con el permiso general (la administración de la app) y si es otro; si
	 *   no, se ignora en silencio (el Laravel lo descarta igual), así una asociación que edita la novedad de su
	 *   comercio no se la queda y con `_own` no se puede regalar nada a nadie (H15);
	 * - novedades: el dueño final y el negocio final cumplen la regla de R70 (si no, 422).
	 * Devuelve el mismo array de validar(), con 'sin_permiso' => true si hay que responder 401.
	 */
	public static function permitir_dueno( string $recurso, array $resultado, ?WP_Post $elemento = null, ?int $usuario = null ): array {
		$usuario = $usuario ?? get_current_user_id();
		$permiso = self::PERMISOS[ $recurso ] ?? null;
		foreach ( self::campos( $recurso ) as $app => $campo ) {
			if ( empty( $campo['dueno'] ) || null === $permiso || ! array_key_exists( $campo['guardado'], $resultado['valores'] ) ) {
				continue;
			}
			$propuesto = (int) $resultado['valores'][ $campo['guardado'] ];
			if ( null === $elemento ) {
				if ( ! ZN_Roles::puede_crear( $permiso, $propuesto, $usuario ) ) {
					$resultado['sin_permiso'] = true;
				}
				continue;
			}
			$cuenta    = get_userdata( $usuario );
			$general   = $cuenta && 'administrator' === ZN_Roles::rol_app( $cuenta ) && ZN_Roles::puede( $permiso, null, $usuario );
			$guardado  = (int) get_post_meta( $elemento->ID, $campo['guardado'], true );
			if ( ! $general || $propuesto === $guardado ) {
				unset( $resultado['valores'][ $campo['guardado'] ] );
			}
		}

		// R70: el dueño de una novedad de un negocio es el del negocio, su asociación o federación, o la administración.
		if ( ZN_Eventos::TIPO === $recurso && ! isset( $resultado['errores']['userId'] ) && ! isset( $resultado['errores']['businessId'] ) ) {
			$valor   = static fn( string $clave ) => array_key_exists( $clave, $resultado['valores'] ) ? (int) $resultado['valores'][ $clave ] : ( $elemento ? (int) get_post_meta( $elemento->ID, $clave, true ) : 0 );
			$negocio = $valor( 'business_id' );
			$dueno   = $valor( 'user_id' );
			if ( $negocio && $dueno && ! ZN_Eventos::dueno_valido_para_negocio( $dueno, $negocio ) ) {
				$app                           = array_key_exists( 'business_id', $resultado['valores'] ) ? 'businessId' : 'userId';
				$resultado['errores'][ $app ][] = self::mensaje( 'exists', $app );
			}
		}
		return $resultado;
	}

	/**
	 * Guarda los valores de validar() (nombre guardado => valor; null = borrar) en su sitio (R80):
	 * - entradas (negocio, evento, sorteo): post_title y post_content en la entrada; el resto, campos personalizados;
	 * - comentarios (reseña, pregunta): comment_post_ID y comment_content en el comentario; comment_approved con
	 *   wp_set_comment_status() (con sus ganchos: puntuación, avisos…); el resto, campos del comentario.
	 * $id null = crear (en borrador salvo que $extra traiga post_status); $extra = campos propios de la entrada o del comentario que no manda la app (post_status,
	 * user_id y comment_author de quien escribe…). Solo escribe campos que están en el esquema.
	 * Devuelve el id, o WP_Error. Las cuentas se guardarán con el registro (R53, R69).
	 *
	 * @return int|WP_Error
	 */
	public static function guardar( string $recurso, ?int $id, array $valores, array $extra = array() ) {
		$conocidos = array();
		foreach ( self::campos( $recurso ) as $campo ) {
			if ( 'urls' === $campo['tipo'] ) {
				for ( $i = 0; $i < $campo['max']; $i++ ) {
					$conocidos[] = "{$campo['guardado']}_{$i}";
				}
			} else {
				$conocidos[] = $campo['guardado'];
			}
		}
		$valores = array_intersect_key( $valores, array_flip( $conocidos ) );

		if ( in_array( $recurso, array( ZN_Negocios::TIPO, ZN_Eventos::TIPO, ZN_Sorteos::TIPO ), true ) ) {
			return self::guardar_entrada( $recurso, $id, $valores, $extra );
		}
		if ( in_array( $recurso, array( ZN_Comentarios::RESENA, ZN_Comentarios::PREGUNTA ), true ) ) {
			return self::guardar_comentario( $recurso, $id, $valores, $extra );
		}
		return new WP_Error( 'zn_sin_guardado', "No se sabe guardar «{$recurso}»." );
	}

	/** @return int|WP_Error */
	private static function guardar_entrada( string $tipo, ?int $id, array $valores, array $extra ) {
		$propios = array_intersect_key( $valores, array_flip( array( 'post_title', 'post_content' ) ) );
		$propios = array_map( static fn( $v ) => (string) $v, $propios ) + $extra;
		if ( null === $id ) {
			// Sin post_status en $extra queda en borrador (lo de WordPress): cada ruta dice si publica, y un olvido no publica nada.
			$id = wp_insert_post( wp_slash( array_merge( array( 'post_type' => $tipo, 'post_title' => '', 'post_content' => '' ), $propios ) ), true );
		} elseif ( $propios ) {
			if ( get_post_type( $id ) !== $tipo ) {
				return new WP_Error( 'zn_no_existe', 'No existe.' );
			}
			$id = wp_update_post( wp_slash( array( 'ID' => $id ) + $propios ), true );
		} elseif ( get_post_type( $id ) !== $tipo ) {
			return new WP_Error( 'zn_no_existe', 'No existe.' );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		foreach ( array_diff_key( $valores, array_flip( array( 'post_title', 'post_content' ) ) ) as $clave => $valor ) {
			if ( null === $valor ) {
				delete_post_meta( $id, $clave );
			} else {
				update_post_meta( $id, $clave, wp_slash( $valor ) );
			}
		}
		return (int) $id;
	}

	/** @return int|WP_Error */
	private static function guardar_comentario( string $tipo, ?int $id, array $valores, array $extra ) {
		// Aprobar o no (published de la pregunta, o la ruta en $extra: las reseñas se publican al momento) siempre
		// con wp_set_comment_status().
		$aprobado = $valores['comment_approved'] ?? $extra['comment_approved'] ?? null;
		unset( $valores['comment_approved'], $extra['comment_approved'] );
		// El user_id de $extra es quien escribe (columna del comentario); el de $valores, el comercio destinatario de
		// una pregunta (campo del comentario). No se mezclan.
		$columnas = array_flip( array( 'comment_post_ID', 'comment_content' ) );
		$propios  = array_intersect_key( $valores, $columnas ) + $extra;
		$metas    = array_diff_key( $valores, $columnas );

		if ( null === $id ) {
			// Se crea sin aprobar y se aprueba después con wp_set_comment_status(), para que pasen sus ganchos.
			$id = wp_insert_comment( wp_slash( array_merge( array( 'comment_type' => $tipo, 'comment_content' => '' ), $propios, array( 'comment_approved' => 0 ) ) ) );
			if ( ! $id ) {
				return new WP_Error( 'zn_no_guardado', 'No se pudo guardar.' );
			}
		} else {
			$comentario = get_comment( $id );
			if ( ! $comentario || $tipo !== $comentario->comment_type ) {
				return new WP_Error( 'zn_no_existe', 'No existe.' );
			}
			if ( $propios ) {
				$hecho = self::texto_tal_cual( $propios['comment_content'] ?? null, static fn() => wp_update_comment( wp_slash( array( 'comment_ID' => $id ) + $propios ), true ) );
				if ( is_wp_error( $hecho ) ) {
					return $hecho;
				}
			}
		}
		foreach ( $metas as $clave => $valor ) {
			if ( null === $valor ) {
				delete_comment_meta( $id, $clave );
			} else {
				update_comment_meta( $id, $clave, wp_slash( $valor ) );
			}
		}
		if ( null !== $aprobado ) {
			wp_set_comment_status( $id, $aprobado ? 'approve' : 'hold' );
		}
		return (int) $id;
	}

	/**
	 * wp_update_comment() pasa el texto por kses (pre_comment_content), que se come «<3 volveré» o «<12 años»
	 * (R82, como R20); y si quien escribió no tiene unfiltered_html, vuelve a poner kses él mismo. Lo que escribe
	 * una persona se guarda tal cual, igual que al crear con wp_insert_comment(), y la app lo pinta como texto:
	 * justo antes de escribir en la base de datos (wp_update_comment_data, ya sin barras) se repone el texto
	 * validado. El resto de wp_update_comment() (ganchos edit_comment, cachés) sigue igual.
	 */
	private static function texto_tal_cual( ?string $texto, callable $hacer ) {
		if ( null === $texto ) {
			return $hacer();
		}
		$reponer = static function ( $datos ) use ( $texto ) {
			if ( is_array( $datos ) ) {
				$datos['comment_content'] = $texto;
			}
			return $datos;
		};
		add_filter( 'wp_update_comment_data', $reponer, PHP_INT_MAX );
		try {
			return $hacer();
		} finally {
			remove_filter( 'wp_update_comment_data', $reponer, PHP_INT_MAX );
		}
	}

	/** 422 con el formato del Laravel a partir de los errores de validar(). */
	public static function respuesta( array $errores ): WP_REST_Response {
		return ZN_Errores::no_valido( $errores );
	}

	/**
	 * Comprueba un valor contra la regla de su campo. Devuelve ['valores' => nombre guardado => valor] o
	 * ['errores' => campo => [mensajes]]; en 'codigo' va la regla que falló (para los avisos de wp-admin).
	 */
	public static function comprobar( array $campo, $valor, string $app ): array {
		$fallo = static fn( string $regla, array $mas = array(), ?string $nombre = null ) => array(
			'errores' => array( $nombre ?? $app => array( self::mensaje( $regla, $nombre ?? $app, $mas ) ) ),
			'codigo'  => $regla,
			'mas'     => $mas,
		);
		$obligatorio = ! empty( $campo['obligatorio'] );

		if ( null === $valor ) {
			if ( $obligatorio ) {
				return $fallo( 'required' );
			}
			return array( 'valores' => self::borrar( $campo ) );
		}
		// La contraseña no se recorta: «        » (8 espacios) la acepta la app.
		$en_blanco = 'contrasena' === $campo['tipo'] ? '' === $valor : is_string( $valor ) && '' === trim( $valor );
		if ( $en_blanco ) {
			return $fallo( $obligatorio ? 'required' : 'filled' );
		}

		switch ( $campo['tipo'] ) {
			case 'texto':
			case 'parrafos':
			case 'escrito':
				if ( ! is_string( $valor ) ) {
					return $fallo( 'string' );
				}
				$texto = self::limpiar_texto( $campo['tipo'], $valor );
				if ( '' === $texto ) {
					return $fallo( $obligatorio ? 'required' : 'filled' );
				}
				if ( isset( $campo['max'] ) && mb_strlen( $texto ) > $campo['max'] ) {
					return $fallo( 'max', array( ':max' => $campo['max'] ) );
				}
				return array( 'valores' => array( $campo['guardado'] => $texto ) );

			case 'contrasena':
				if ( ! is_string( $valor ) ) {
					return $fallo( 'string' );
				}
				if ( mb_strlen( $valor ) < $campo['min'] ) {
					return $fallo( 'min_string', array( ':min' => $campo['min'] ) );
				}
				if ( mb_strlen( $valor ) > $campo['max'] ) {
					return $fallo( 'max', array( ':max' => $campo['max'] ) );
				}
				// Tal cual: la cifra wp_insert_user / wp_set_password.
				return array( 'valores' => array( $campo['guardado'] => $valor ) );

			case 'telefono':
				// Como la app: hasta 20 caracteres y, sin espacios, de 9 a 15 cifras con un + delante opcional.
				if ( ! is_string( $valor ) ) {
					return $fallo( 'string' );
				}
				$telefono = trim( $valor );
				if ( mb_strlen( $telefono ) > $campo['max'] ) {
					return $fallo( 'max', array( ':max' => $campo['max'] ) );
				}
				if ( ! preg_match( '/^\+?\d{9,15}$/', preg_replace( '/\s/', '', $telefono ) ) ) {
					return $fallo( 'regex' );
				}
				return array( 'valores' => array( $campo['guardado'] => $telefono ) );

			case 'codigo':
				$codigo = is_int( $valor ) ? sprintf( '%06d', $valor ) : ( is_string( $valor ) ? trim( $valor ) : '' );
				if ( ! preg_match( '/^\d{6}$/', $codigo ) ) {
					return $fallo( 'digits', array( ':digits' => 6 ) );
				}
				return array( 'valores' => array( $campo['guardado'] => $codigo ) );

			case 'pais':
				if ( ! is_string( $valor ) ) {
					return $fallo( 'string' );
				}
				$pais = strtoupper( trim( $valor ) );
				if ( 2 !== strlen( $pais ) ) {
					return $fallo( 'size', array( ':size' => 2 ) );
				}
				if ( ! ctype_alpha( $pais ) ) {
					return $fallo( 'alpha' );
				}
				return array( 'valores' => array( $campo['guardado'] => $pais ) );

			case 'email':
				if ( ! is_string( $valor ) ) {
					return $fallo( 'string' );
				}
				$email = trim( $valor );
				if ( ! is_email( $email ) ) {
					return $fallo( 'email' );
				}
				if ( isset( $campo['max'] ) && strlen( $email ) > $campo['max'] ) {
					return $fallo( 'max', array( ':max' => $campo['max'] ) );
				}
				return array( 'valores' => array( $campo['guardado'] => sanitize_email( $email ) ) );

			case 'url':
				if ( ! is_string( $valor ) ) {
					return $fallo( 'string' );
				}
				$url = self::url( $valor );
				if ( null === $url ) {
					return $fallo( 'url' );
				}
				if ( isset( $campo['max'] ) && strlen( $url ) > $campo['max'] ) {
					return $fallo( 'max', array( ':max' => $campo['max'] ) );
				}
				return array( 'valores' => array( $campo['guardado'] => $url ) );

			case 'urls':
				// Lista o {"0": …}; los huecos que no llegan no se tocan (como processMedia del Laravel).
				if ( ! is_array( $valor ) ) {
					return $fallo( 'array' );
				}
				if ( count( $valor ) > $campo['max'] || array_diff( array_keys( $valor ), range( 0, $campo['max'] - 1 ) ) ) {
					return $fallo( 'max_array', array( ':max' => $campo['max'] ) );
				}
				$valores = array();
				foreach ( $valor as $i => $elemento ) {
					$nombre = "$app.$i";
					if ( null === $elemento ) {
						$valores[ "{$campo['guardado']}_{$i}" ] = null;
						continue;
					}
					if ( ! is_string( $elemento ) ) {
						return $fallo( 'string', array(), $nombre );
					}
					if ( '' === trim( $elemento ) ) {
						return $fallo( 'filled', array(), $nombre );
					}
					$url = self::url( $elemento );
					if ( null === $url ) {
						return $fallo( 'url', array(), $nombre );
					}
					$valores[ "{$campo['guardado']}_{$i}" ] = $url;
				}
				return array( 'valores' => $valores );

			case 'fecha':
				$fecha = is_string( $valor ) ? DateTimeImmutable::createFromFormat( '!' . self::FORMATO_FECHA, trim( $valor ) ) : false;
				if ( ! $fecha || $fecha->format( self::FORMATO_FECHA ) !== trim( $valor ) ) {
					return $fallo( 'date_format', array( ':format' => self::FORMATO_FECHA ) );
				}
				return array( 'valores' => array( $campo['guardado'] => $fecha->format( self::FORMATO_FECHA ) ) );

			case 'booleano':
				if ( ! in_array( $valor, array( true, false, 1, 0, '1', '0' ), true ) ) {
					return $fallo( 'boolean' );
				}
				return array( 'valores' => array( $campo['guardado'] => in_array( $valor, array( true, 1, '1' ), true ) ? 1 : 0 ) );

			case 'numero':
				if ( is_bool( $valor ) || ! is_numeric( $valor ) ) {
					return $fallo( 'numeric' );
				}
				$numero = (float) $valor;
				if ( isset( $campo['entre'] ) && ( $numero < $campo['entre'][0] || $numero > $campo['entre'][1] ) ) {
					return $fallo( 'between', array( ':min' => $campo['entre'][0], ':max' => $campo['entre'][1] ) );
				}
				$numero = isset( $campo['decimales'] ) ? round( $numero, $campo['decimales'] ) : $numero;
				return array( 'valores' => array( $campo['guardado'] => (string) $numero ) );

			case 'entero':
			case 'opciones':
			case 'cuenta':
			case 'negocio':
				$entero = self::entero( $valor );
				if ( null === $entero ) {
					return $fallo( 'integer' );
				}
				if ( isset( $campo['min'] ) && $entero < $campo['min'] ) {
					return $fallo( 'min', array( ':min' => $campo['min'] ) );
				}
				if ( 'opciones' === $campo['tipo'] && ! in_array( $entero, $campo['opciones'], true ) ) {
					return $fallo( 'exists' );
				}
				if ( 'cuenta' === $campo['tipo'] ) {
					$cuenta = $entero > 0 ? get_userdata( $entero ) : false;
					if ( ! $cuenta || ! ZN_Roles::rol_app( $cuenta ) ) {
						return $fallo( 'exists' );
					}
				}
				if ( 'negocio' === $campo['tipo'] && ! ZN_API::entrada( $entero, ZN_Negocios::TIPO ) ) {
					return $fallo( 'exists' );
				}
				return array( 'valores' => array( $campo['guardado'] => $entero ) );
		}
		return $fallo( 'string' );
	}

	/** Aviso en español para wp-admin a partir del resultado de comprobar() con error. */
	public static function aviso( array $campo, array $resultado ): string {
		$motivo = strtr( self::AVISOS[ $resultado['codigo'] ] ?? 'no es válido', $resultado['mas'] ?? array() );
		return sprintf( '%s %s; no se ha cambiado.', $campo['etiqueta'] ?? 'El campo', $motivo );
	}

	/** Texto del Laravel con el nombre del campo como lo pinta él: «categoryId» → «category id». */
	public static function mensaje( string $regla, string $app, array $mas = array() ): string {
		return strtr( self::MENSAJES[ $regla ], array( ':attribute' => self::atributo( $app ) ) + $mas );
	}

	private static function atributo( string $app ): string {
		return str_replace( '_', ' ', strtolower( preg_replace( '/(?<=[a-z0-9])([A-Z])/', '_$1', $app ) ) );
	}

	/** Un campo puesto a null: todos los huecos si es una lista de fotos o vídeos. */
	private static function borrar( array $campo ): array {
		if ( 'urls' !== $campo['tipo'] ) {
			return array( $campo['guardado'] => null );
		}
		$valores = array();
		for ( $i = 0; $i < $campo['max']; $i++ ) {
			$valores[ "{$campo['guardado']}_{$i}" ] = null;
		}
		return $valores;
	}

	/**
	 * - texto: una línea, sin etiquetas (sanitize_text_field), como lo guardaba ya la ficha;
	 * - parrafos: varias líneas, sin etiquetas; los horarios del Laravel separan día y horas con tabulador;
	 * - escrito: lo que escribe una persona (reseñas, preguntas), tal cual: «<3» no se corta (R20).
	 */
	private static function limpiar_texto( string $tipo, string $texto ): string {
		$texto = wp_check_invalid_utf8( $texto, true );
		switch ( $tipo ) {
			case 'texto':
				return sanitize_text_field( $texto );
			case 'parrafos':
				return trim( sanitize_textarea_field( str_replace( "\t", ': ', $texto ) ) );
		}
		return trim( preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace( "\r\n", "\n", $texto ) ) );
	}

	/** Solo direcciones http(s) con dominio; sin espacios ni nada que esc_url_raw cambie. */
	private static function url( string $valor ): ?string {
		$valor = trim( $valor );
		$host  = (string) wp_parse_url( $valor, PHP_URL_HOST );
		if ( ! preg_match( '#^https?://#i', $valor ) || '' === $host || preg_match( '/\s/', $valor ) || false === filter_var( $valor, FILTER_VALIDATE_URL ) ) {
			return null;
		}
		return esc_url_raw( $valor, array( 'http', 'https' ) ) === $valor ? $valor : null;
	}

	/** 5, "5" o "-5" → entero; 5.0, "5.5", true… → null (la regla integer del Laravel). */
	private static function entero( $valor ): ?int {
		if ( is_int( $valor ) ) {
			return $valor;
		}
		return is_string( $valor ) && preg_match( '/^-?\d{1,18}$/', trim( $valor ) ) ? (int) trim( $valor ) : null;
	}

	private static function tipo_meta( string $tipo ): string {
		switch ( $tipo ) {
			case 'entero':
			case 'opciones':
			case 'cuenta':
			case 'negocio':
			case 'booleano':
				return 'integer';
			case 'numero':
				return 'number';
		}
		return 'string';
	}
}
