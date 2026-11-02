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
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'etiqueta' => 'El dueño' ),
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
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'etiqueta' => 'El dueño' ),
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
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'etiqueta' => 'El dueño' ),
				);

			// Reseñas y preguntas son comentarios: el autor sale siempre del token, nunca del cuerpo (H21).
			case ZN_Comentarios::RESENA:
				return array(
					'businessId'  => array( 'guardado' => 'comment_post_ID', 'tipo' => 'negocio', 'obligatorio' => true, 'etiqueta' => 'El negocio' ),
					// El Laravel no limita la puntuación (H13); aquí, de 1 a 5 como la app.
					'score'       => array( 'guardado' => 'score', 'tipo' => 'numero', 'entre' => array( 1, 5 ), 'obligatorio' => true, 'etiqueta' => 'La puntuación' ),
					'title'       => array( 'guardado' => 'title', 'tipo' => 'escrito', 'max' => 50, 'etiqueta' => 'El título' ),
					'description' => array( 'guardado' => 'comment_content', 'tipo' => 'escrito', 'max' => 2000, 'etiqueta' => 'El texto' ),
				);

			case ZN_Comentarios::PREGUNTA:
				return array(
					// El comercio destinatario (que sea un comercio lo comprueba la ruta, como el Laravel).
					'userId'      => array( 'guardado' => 'user_id', 'tipo' => 'cuenta', 'obligatorio' => true, 'etiqueta' => 'El comercio' ),
					'question'    => array( 'guardado' => 'comment_content', 'tipo' => 'escrito', 'max' => 2000, 'obligatorio' => true, 'etiqueta' => 'La pregunta' ),
					'answer'      => array( 'guardado' => 'answer', 'tipo' => 'escrito', 'max' => 2000, 'etiqueta' => 'La respuesta' ),
					'published'   => array( 'guardado' => 'comment_approved', 'tipo' => 'booleano', 'etiqueta' => 'Publicada' ),
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
		if ( is_string( $valor ) && '' === trim( $valor ) ) {
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
