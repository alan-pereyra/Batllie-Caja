# Batllie Caja & Pedidos POS (WooCommerce)

Plugin profesional de punto de venta, mostrador y cocina diseñado para **Empralidad.com.ar/Batllie**. Proporciona una interfaz aislada, rápida y táctil para gestionar pedidos entrantes de WooCommerce en tiempo real con notificaciones sonoras, control de estados y catálogo de productos independiente.

---

## 🚀 Características Principales

1. **Terminal de Caja con Acceso Seguro**:
   - Acceso protegido por usuario y contraseña.
   - Formulario de login directo dentro de la misma pantalla (sin redirigir a `/wp-login.php`).
   - Cierre de sesión seguro con un clic.

2. **Vista Aislada (Modo Mostrador / Pantalla Completa)**:
   - Se activa insertando el shortcode `[batllie_caja]` en cualquier página de WordPress.
   - Oculta automáticamente menús, cabeceras y pies de página del tema activo para evitar distracciones en la caja.
   - Botón integrado para pasar a pantalla completa nativa.

3. **Monitor de Pedidos en Tiempo Real**:
   - Tarjetas de pedidos de alta legibilidad con: número, tiempo transcurrido, cliente, teléfono directo (con enlace a WhatsApp), dirección de entrega/retiro, desglose de ítems, cantidades y notas.
   - **Gestión Rápida de Estados**: Botones directos para cambiar el estado del pedido:
     - ⏳ Pendiente
     - 👨‍🍳 En Preparación (Processing)
     - ✅ Listo / Completado
     - ❌ Cancelar
   - Filtros de estado por pestañas y buscador en tiempo real.

4. **Alerta Sonora (Campana de Mostrador)**:
   - Sonido acústico nítido de campana generado con **Web Audio API** (sin depender de archivos externos que puedan fallar).
   - Detección automática de nuevos pedidos vía sondeo (polling configurable).
   - Banner animado con visualizador de ondas sonoras al recibir un nuevo pedido.
   - Botón para silenciar o reactivar el sonido en cualquier momento.
   - Botón "Test" para comprobar el audio al iniciar el turno.

5. **Gestión de Productos (Pestaña Separada)**:
   - Pestaña independiente del monitor de pedidos.
   - Búsqueda y filtrado por categoría de productos de la tienda.
   - Indicadores de inventario / stock en tiempo real.
   - Modal para **Cargar Nuevo Producto** directamente a WooCommerce (Nombre, Precio regular, Precio de oferta, Categoría, SKU, Control de Stock y Descripción).

6. **Colores 100% Configurables**:
   - Panel de administración en WordPress (*Batllie Caja* en el menú lateral).
   - Selectores de color nativos (`wp-color-picker`) para personalizar:
     - Fondo principal
     - Fondo de cabecera / topbar
     - Fondo y bordes de tarjetas
     - Textos y títulos
     - Botones primarios y hover
     - Color de cada estado (*Pendiente*, *En preparación*, *Completado*, *Cancelado*)
     - Frecuencia de actualización en segundos

---

## 📦 Instalación

1. Comprime la carpeta `emp-caja` en formato `.zip` (o descarga `emp-caja.zip`).
2. En tu panel de administración de WordPress (`Empralidad.com.ar/Batllie/wp-admin`):
   - Ve a **Plugins > Añadir nuevo**.
   - Haz clic en **Subir plugin**.
   - Selecciona el archivo `emp-caja.zip` y haz clic en **Instalar ahora**.
   - Activa el plugin.
3. Crea una nueva página en WordPress:
   - Título: **Caja Batllie** (o **Punto de Venta**)
   - Contenido: Inserta el shortcode:
     ```text
     [batllie_caja]
     ```
   - Publica la página.
4. Para personalizar los colores:
   - Ve a **Batllie Caja** en el menú de la barra lateral de WordPress.
   - Ajusta los colores que desees y haz clic en **Guardar Cambios de Configuración**.
