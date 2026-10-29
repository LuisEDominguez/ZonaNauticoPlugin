// Ficha del negocio en wp-admin: elegir y quitar fotos con la biblioteca de medios de WordPress.
jQuery(function ($) {
	$('.zn-foto').each(function () {
		var $caja = $(this);
		var $img = $caja.find('img');
		var $campo = $caja.find('input[type=hidden]');
		var $quitar = $caja.find('.zn-quitar-foto');
		var marco;

		$caja.find('.zn-elegir-foto').on('click', function () {
			if (!marco) {
				marco = wp.media({ title: 'Elegir foto del negocio', button: { text: 'Usar esta foto' }, library: { type: 'image' }, multiple: false });
				marco.on('select', function () {
					var foto = marco.state().get('selection').first().toJSON();
					$campo.val(foto.url);
					$img.attr('src', foto.url).show();
					$quitar.show();
				});
			}
			marco.open();
		});

		$quitar.on('click', function () {
			$campo.val('');
			$img.attr('src', '').hide();
			$quitar.hide();
		});
	});
});
