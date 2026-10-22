# Caja & Comandas POS (Boliches & Restaurantes)

> **Branch:** `caja-boliches-restaurantes`  
> Variación especializada del sistema de caja y punto de venta WooCommerce para gastronomía nocturna, boliches, bares, terrazas y restaurantes.

Plugin profesional de punto de venta táctil, comandas y mostrador diseñado para WooCommerce. Proporciona una interfaz aislada, ultrarrápida y táctil optimizada para operar en barras de boliche, restaurantes y servicios de salón.

---

## 🍸 Características Exclusivas de la Variación Gastronómica

1. **Gestión Ágil de Sectores, Barras y Mesas**:
   - Selector táctil de ubicación en tiempo real en la pantalla de cobro:
     - 🍸 **Barra Principal**
     - 🍾 **Barra VIP**
     - 🍹 **Terraza**
     - 🍽️ **Mesas de Salón** (Selector rápido 1 a 12+ o número/nombre personalizado)
     - 📦 **Take Away / Mostrador**
   - Personalizable desde la pestaña de Configuración: listas de barras/sectores a medida de cada local.

2. **Speed Cash (Calculadora de Vuelto Rápido con Billetes Táctiles)**:
   - Diseñado para el ritmo acelerado de la barra nocturna:
     - Botón **✓ Exacto** (asigna el total exacto con un toque).
     - Botones táctiles de billetes: **+$100**, **+$200**, **+$500**, **+$1.000**, **+$2.000**, **+$5.000**, **+$10.000**, **+$20.000**.
     - Cálculo en vivo del vuelto a entregar con alerta visual si el dinero entregado es insuficiente.
     - Botón **Limpiar** para reiniciar el conteo.

3. **Gestión de Propinas y Servicio de Mesa**:
   - Chips de propina rápida con un toque: **0%**, **5%**, **10%**, **15%** y **Libre**.
   - Input libre para propinas de monto personalizado.
   - Suma automática y transparente en el subtotal, total general y como ítem de tarifa (*Fee*) en la orden de WooCommerce.

4. **Impresión Térmica de Comandas y Tickets (80mm / 58mm)**:
   - **🖨️ Comanda de Preparación**: Formato térmico de alta visibilidad para cocina y bartender (banner de mesa/barra destacado, notas del cliente en grande, listado de tragos/platos con variaciones y sin precios).
   - **🧾 Ticket de Venta**: Comprobante para el cliente con encabezado del local, desglose de consumición, propina, medios de pago, dinero entregado y vuelto calculado.
   - Impresión directa mediante iframe oculto sin alertas ni bloqueos de ventanas emergentes en navegadores táctiles o tablets.
   - Reimpresión instantánea con un clic desde cualquier tarjeta de pedido o desde el modal de confirmación de venta.

5. **Monitor de Salón y Barras en Tiempo Real**:
   - Badge distintivo en cada tarjeta indicando el sector y mesa (ej: `🍸 Barra VIP` o `🍽️ Mesa #4`).
   - Filtro dedicado en el monitor de pedidos para aislar comandas de un sector específico (ej: ver solo pedidos de *Cocina*, *Barra Principal* o *Terraza*).
   - Acceso inmediato a comandas y tickets desde el monitor de pedidos.

6. **Configuración Gastronómica Dedicada (Card 10)**:
   - Selección de Perfil: **Boliche / Bar Nocturno** vs **Restaurante / Salón**.
   - Nombre de fantasía del local para tickets.
   - Lista editable de sectores y barras.
   - Interruptores para habilitar/deshabilitar calculadora de billetes rápidos y propinas.
   - Mensaje de pie de ticket personalizado (ej: *¡Gracias por su visita!*).

---

## 🚀 Características Base del Sistema

- **Terminal Aislada con Acceso Protegido**: Shortcode `[batllie_caja]` sin distracciones ni barras de navegación de WordPress.
- **Campana Sonora Acústica**: Sonido de aviso con Web Audio API al ingresar pedidos nuevos.
- **Gestión de Inventario y Productos**: Búsqueda por categorías, variaciones de atributos y actualización de stock en tiempo real.
- **Despacho y Envío Integrado**: Métodos de retiro local, control de paquetes y notas internas del pedido.
- **100% Personalizable en Colores**: Ajuste de estilos, contrastes y fondos desde el panel de administración.

---

## 📦 Instalación y Puesta en Marcha

1. Asegúrate de estar en la rama:
   ```bash
   git checkout caja-boliches-restaurantes
   ```
2. Comprime o sube la carpeta `emp-caja` en el directorio de plugins de WordPress (`wp-content/plugins/emp-caja`).
3. Activa el plugin **Caja & Comandas POS (Boliches & Restaurantes)** en el panel de WordPress.
4. Crea una página con el shortcode:
   ```text
   [batllie_caja]
   ```
5. En la pestaña **Ajustes** dentro del sistema POS o en el menú de WordPress, configura el nombre del establecimiento, las barras de tu local y activa las opciones de Speed Cash y propinas.
