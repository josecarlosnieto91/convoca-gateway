<?php
/**
 * Doble del andamiaje de las pantallas del núcleo (convoca-core#16).
 *
 * `Convoca\Core\Admin\Pantalla_Admin` vive en el repositorio del núcleo, que en el CI aislado de
 * este plugin no está a mano. El arranque de las pruebas carga el fichero REAL si el núcleo está
 * clonado al lado (es lo que hay en local) y, si no, este doble: solo existe para que la clase se
 * pueda declarar y analizar. Un doble que se quedara haciendo el trabajo mediría algo que no existe.
 */

namespace Convoca\Core\Admin {

    trait Andamiaje_Admin
    {
        abstract protected function ganchos(): array;
        protected function editor_propio(): ?array { return null; }
        protected function nodo_barra(): ?array { return null; }
        public function __construct() {}
        protected function registrar_subpagina( ?string $padre, string $titulo, string $menu, string $capacidad, string $slug, callable $callback ): string { return ''; }
        protected function encolar_estilo_comun(): void {}
        protected function exigir_guardado( string $nonce, string $capacidad, string $mensaje ): void {}
        public function redirigir_editor_propio(): void {}
        public static function redirigir_pantallas_crudas( array $destinos ): void {}
        public function apuntar_nodo_barra( $wp_admin_bar ): void {}
    }

    abstract class Pantalla_Admin
    {
        use Andamiaje_Admin;
    }
}
