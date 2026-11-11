(function () {
  const app = document.querySelector('[data-restaurant-app]');
  if (!app) return;

  const i18n = {
    'zh-CN': {
      'nav.menu': '菜单',
      'nav.favorites': '招牌',
      'nav.visit': '到店',
      'nav.order': '订单凭证',
      'receipt.title': '订单凭证',
      'receipt.number': '订单号',
      'receipt.ticket': '厨房票',
      'receipt.ready': '可以取餐，请向店员出示订单号',
      'receipt.completed': '订单已完成，请勿重复取餐',
      'receipt.waiting': '请查看最新状态，等店员确认后取餐',
      'receipt.refresh': '刷新订单状态',
      'receipt.hint': '店员以后台付款及未领取状态为准，不只凭截图交餐。退款仅由店家人工处理。换设备或找不到订单时请联系门店。',
      'receipt.payment.unconfirmed': '付款待确认',
      'receipt.payment.paid': '付款已确认',
      'receipt.payment.refunded': '已退款',
      'receipt.payment.partially_refunded': '部分退款，需人工核实',
      'receipt.payment.cancelled': '已取消',
      'cta.pickup': '自取下单',
      'cta.delivery': '外卖配送',
      'cta.menu': '查看菜单',
      'mode.pickup': '自取',
      'mode.delivery': '配送',
      'search.label': '搜索菜单',
      'search.placeholder': '搜索菜品、分类或关键词',
      'search.results': '找到 {count} 道菜',
      'category.label': '菜品分类',
      'section.featured.kicker': 'House Favorites',
      'section.featured.title': '本店招牌',
      'section.menu.kicker': 'Full Menu',
      'section.menu.title': '完整菜单',
      'menuSection.label': '用餐菜单',
      'menuSection.all': '全部',
      'menuSection.lunch': '午餐',
      'menuSection.dinner': '晚餐',
      'menuSection.kids': '儿童餐',
      'section.gallery.kicker': 'Gallery',
      'section.gallery.title': '门店与菜品',
      'section.about.kicker': '关于我们',
      'section.visit.kicker': 'Visit Us',
      'section.visit.title': '地址与营业时间',
      'label.address': '地址',
      'label.phone': '电话',
      'label.hours': '今日营业',
      'footer.powered': 'Powered by Daiying CMS Restaurant.',
      'cart.pending': '已选择菜品',
      'cart.checkout': '去结算',
      'button.add': '加入餐车',
      'button.unavailable': '暂不可点',
      'empty.menu': '菜单还没有上架菜品。',
      'empty.search': '没有找到匹配菜品。',
      'empty.featured': '还没有设置招牌推荐。',
      'plugin.missing': '餐厅外卖插件未启用，店面数据暂时不可用。',
      'ordering.notReady': '点餐接口还未接入，当前仅展示菜单。',
      'status.open': '营业中',
      'status.closed': '已打烊',
      'status.closing_soon': '即将打烊',
      'status.paused': '暂停接单'
      ,'status.sold_out': '已售罄'
      ,'status.pickup_unavailable': '自取暂停'
      ,'status.delivery_unavailable': '配送暂停'
      ,'button.close': '关闭'
      ,'cart.view': '查看购物车'
      ,'cart.title': '购物车'
      ,'cart.empty': '购物车是空的。'
      ,'cart.remove': '移除'
      ,'cart.subtotal': '菜品与加料小计'
      ,'cart.delivery': '配送费'
      ,'cart.tax': '税费'
      ,'cart.total': '总计'
      ,'cart.pendingTotal': '暂计（税费待确认）'
      ,'tax.pending': '门店税率尚未确认，暂不可付款。'
      ,'tax.legacy': '历史订单未记录独立税费明细，保留原提交金额。'
      ,'cart.summary': '件商品'
      ,'cart.summary.one': '件商品'
      ,'cart.status': '订单状态'
      ,'checkout.name': '姓名'
      ,'checkout.phone': '电话'
      ,'checkout.email': '邮箱'
      ,'checkout.address': '配送地址'
      ,'checkout.postal': '配送邮编'
      ,'checkout.time': '预约时间（留空表示尽快）'
      ,'checkout.preorderNote': '按门店时区预约，至少提前 {lead} 分钟，最多提前 {days} 天。'
      ,'checkout.deliveryFee': '配送费：{fee} {currency}（结账时计入总额）'
      ,'checkout.payment': '支付方式'
      ,'checkout.noPayment': '暂无可用支付方式'
      ,'checkout.unavailable': '当前无法结算。'
      ,'product.quantity': '数量'
      ,'product.notes': '备注'
      ,'product.notesHint': '不要鸡肉、不要米饭等请写在备注里。加料请选择上方“加”；特殊变更需门店确认，不自动减价，过敏需求请联系门店。'
      ,'product.customize': '数量 / 备注'
      ,'product.addon': '加'
      ,'product.addonChoice': '选择要加的东西'
      ,'product.removeAddon': '移除加料'
      ,'product.required': '请选择必选规格。'
      ,'product.options': '请选择规格'
      ,'button.soldOut': '售罄'
      ,'label.map': '地图'
      ,'link.map': '查看路线'
      ,'nav.label': '主导航'
      ,'category.label': '菜单分类'
      ,'language.label': '语言'
      ,'plugin.admin': '管理员：请启用餐厅外卖插件。'
      ,'error.request': '操作失败，请重试。'
      ,'image.placeholder': '暂无图片'
      ,'hero.noPhoto': '真实门头照片待上传'
      ,'cart.state.open': '待结算'
      ,'cart.state.checkout_started': '等待支付'
      ,'cart.state.paid': '已支付'
      ,'cart.state.ticketed': '制作中'
      ,'ticket.state.new': '已接收'
      ,'ticket.state.accepted': '已接单'
      ,'ticket.state.preparing': '制作中'
      ,'ticket.state.ready': '可取餐'
      ,'ticket.state.completed': '已完成'
      ,'ticket.state.cancelled': '已取消'
    },
    'en-US': {
      'nav.menu': 'Menu',
      'nav.favorites': 'Favorites',
      'nav.visit': 'Visit',
      'nav.order': 'My order',
      'receipt.title': 'Order receipt',
      'receipt.number': 'Order number',
      'receipt.ticket': 'Kitchen ticket',
      'receipt.ready': 'Ready for pickup. Show your order number to staff.',
      'receipt.completed': 'Order completed. Do not collect it again.',
      'receipt.waiting': 'Check the latest status and wait for staff confirmation.',
      'receipt.refresh': 'Refresh order status',
      'receipt.hint': 'Staff verify payment and collection in the admin, not screenshots alone. Only the merchant can initiate a manual refund. Contact the restaurant if using another device or your order is missing.',
      'receipt.payment.unconfirmed': 'Payment awaiting confirmation',
      'receipt.payment.paid': 'Payment confirmed',
      'receipt.payment.refunded': 'Refunded',
      'receipt.payment.partially_refunded': 'Partially refunded. Staff review required.',
      'receipt.payment.cancelled': 'Cancelled',
      'cta.pickup': 'Order Pickup',
      'cta.delivery': 'Order Delivery',
      'cta.menu': 'View Menu',
      'mode.pickup': 'Pickup',
      'mode.delivery': 'Delivery',
      'search.label': 'Search menu',
      'search.placeholder': 'Search dishes, categories or keywords',
      'search.results': '{count} dishes found',
      'category.label': 'Categories',
      'section.featured.kicker': 'House Favorites',
      'section.featured.title': 'House Favorites',
      'section.menu.kicker': 'Full Menu',
      'section.menu.title': 'Full Menu',
      'menuSection.label': 'Meal menus',
      'menuSection.all': 'All',
      'menuSection.lunch': 'Lunch',
      'menuSection.dinner': 'Dinner',
      'menuSection.kids': 'Kids',
      'section.gallery.kicker': 'Gallery',
      'section.gallery.title': 'Storefront & Food',
      'section.about.kicker': 'About',
      'section.visit.kicker': 'Visit Us',
      'section.visit.title': 'Hours & Location',
      'label.address': 'Address',
      'label.phone': 'Phone',
      'label.hours': 'Today',
      'footer.powered': 'Powered by Daiying CMS Restaurant.',
      'cart.pending': 'Selected items',
      'cart.checkout': 'Checkout',
      'button.add': 'Add',
      'button.unavailable': 'Unavailable',
      'empty.menu': 'No menu items are published yet.',
      'empty.search': 'No matching menu items.',
      'empty.featured': 'No house favorites yet.',
      'plugin.missing': 'Restaurant ordering plugin is not enabled.',
      'ordering.notReady': 'Ordering API is not connected yet. Menu display only.',
      'status.open': 'Open',
      'status.closed': 'Closed',
      'status.closing_soon': 'Closing Soon',
      'status.paused': 'Temporarily Paused'
      ,'status.sold_out': 'Sold Out'
      ,'status.pickup_unavailable': 'Pickup Unavailable'
      ,'status.delivery_unavailable': 'Delivery Unavailable'
      ,'button.close': 'Close'
      ,'cart.view': 'View Cart'
      ,'cart.title': 'Your Cart'
      ,'cart.empty': 'Your cart is empty.'
      ,'cart.remove': 'Remove'
      ,'cart.subtotal': 'Food and add-ons subtotal'
      ,'cart.delivery': 'Delivery fee'
      ,'cart.tax': 'Sales tax'
      ,'cart.total': 'Total'
      ,'cart.pendingTotal': 'Subtotal with fees (tax pending)'
      ,'tax.pending': 'The restaurant has not confirmed its tax rate. Payment is unavailable.'
      ,'tax.legacy': 'This historical order has no separate tax breakdown. Its original amount is unchanged.'
      ,'cart.summary': 'items'
      ,'cart.summary.one': 'item'
      ,'cart.status': 'Order status'
      ,'checkout.name': 'Name'
      ,'checkout.phone': 'Phone'
      ,'checkout.email': 'Email'
      ,'checkout.address': 'Delivery address'
      ,'checkout.postal': 'Delivery postal code'
      ,'checkout.time': 'Schedule time (leave blank for ASAP)'
      ,'checkout.preorderNote': 'Restaurant local time; at least {lead} minutes ahead, up to {days} days.'
      ,'checkout.deliveryFee': 'Delivery fee: {fee} {currency} (included at checkout)'
      ,'checkout.payment': 'Payment method'
      ,'checkout.noPayment': 'No payment method available'
      ,'checkout.unavailable': 'Checkout is currently unavailable.'
      ,'product.quantity': 'Quantity'
      ,'product.notes': 'Notes'
      ,'product.notesHint': 'Write requests such as no chicken or no rice here. Use Add for extras. Changes require restaurant confirmation, do not automatically reduce the price, and allergies need direct contact.'
      ,'product.customize': 'Quantity / Requests'
      ,'product.addon': 'Add'
      ,'product.addonChoice': 'Choose an add-on'
      ,'product.removeAddon': 'Remove extra'
      ,'product.required': 'Choose the required options.'
      ,'product.options': 'Choose options'
      ,'button.soldOut': 'Sold Out'
      ,'label.map': 'Map'
      ,'link.map': 'Get directions'
      ,'nav.label': 'Main navigation'
      ,'category.label': 'Menu categories'
      ,'language.label': 'Language'
      ,'plugin.admin': 'Administrator: enable the restaurant ordering plugin.'
      ,'error.request': 'That did not work. Please try again.'
      ,'image.placeholder': 'No photo yet'
      ,'hero.noPhoto': 'Real storefront photo not provided yet'
      ,'cart.state.open': 'Ready to checkout'
      ,'cart.state.checkout_started': 'Awaiting payment'
      ,'cart.state.paid': 'Paid'
      ,'cart.state.ticketed': 'Preparing'
      ,'ticket.state.new': 'Received'
      ,'ticket.state.accepted': 'Accepted'
      ,'ticket.state.preparing': 'Preparing'
      ,'ticket.state.ready': 'Ready'
      ,'ticket.state.completed': 'Completed'
      ,'ticket.state.cancelled': 'Cancelled'
    },
    'es-US': {
      'nav.menu': 'Menú',
      'nav.favorites': 'Favoritos',
      'nav.visit': 'Visítanos',
      'nav.order': 'Mi pedido',
      'receipt.title': 'Comprobante del pedido',
      'receipt.number': 'Número de pedido',
      'receipt.ticket': 'Ticket de cocina',
      'receipt.ready': 'Listo para recoger. Muestra el número de pedido al personal.',
      'receipt.completed': 'Pedido completado. No lo recojas de nuevo.',
      'receipt.waiting': 'Consulta el estado actual y espera la confirmación del personal.',
      'receipt.refresh': 'Actualizar estado',
      'receipt.hint': 'El personal verifica el pago y la recogida en el panel, no solo capturas. Solo el restaurante inicia reembolsos manuales. Contacta al restaurante si usas otro dispositivo o no encuentras el pedido.',
      'receipt.payment.unconfirmed': 'Pago pendiente de confirmación',
      'receipt.payment.paid': 'Pago confirmado',
      'receipt.payment.refunded': 'Reembolsado',
      'receipt.payment.partially_refunded': 'Reembolso parcial. Requiere revisión del personal.',
      'receipt.payment.cancelled': 'Cancelado',
      'cta.pickup': 'Pedir para recoger',
      'cta.delivery': 'Entrega',
      'cta.menu': 'Ver menú',
      'mode.pickup': 'Recoger',
      'mode.delivery': 'Entrega',
      'search.label': 'Buscar menú',
      'search.placeholder': 'Buscar platos, categorías o palabras clave',
      'search.results': '{count} platos encontrados',
      'category.label': 'Categorías',
      'section.featured.kicker': 'Favoritos de la casa',
      'section.featured.title': 'Favoritos de la casa',
      'section.menu.kicker': 'Menú completo',
      'section.menu.title': 'Menú completo',
      'menuSection.label': 'Menús por comida',
      'menuSection.all': 'Todo',
      'menuSection.lunch': 'Almuerzo',
      'menuSection.dinner': 'Cena',
      'menuSection.kids': 'Niños',
      'section.gallery.kicker': 'Galería',
      'section.gallery.title': 'Local y comida',
      'section.about.kicker': 'Acerca de',
      'section.visit.kicker': 'Visítanos',
      'section.visit.title': 'Horario y ubicación',
      'label.address': 'Dirección',
      'label.phone': 'Teléfono',
      'label.hours': 'Hoy',
      'footer.powered': 'Powered by Daiying CMS Restaurant.',
      'cart.pending': 'Artículos seleccionados',
      'cart.checkout': 'Pagar',
      'button.add': 'Agregar',
      'button.unavailable': 'No disponible',
      'empty.menu': 'Aún no hay platos publicados.',
      'empty.search': 'No hay platos que coincidan.',
      'empty.featured': 'Aún no hay favoritos.',
      'plugin.missing': 'El plugin del restaurante no está activado.',
      'ordering.notReady': 'La API de pedidos aún no está conectada. Solo menú.',
      'status.open': 'Abierto',
      'status.closed': 'Cerrado',
      'status.closing_soon': 'Cierra pronto',
      'status.paused': 'Pausado temporalmente'
      ,'status.sold_out': 'Agotado'
      ,'status.pickup_unavailable': 'Recogida no disponible'
      ,'status.delivery_unavailable': 'Entrega no disponible'
      ,'button.close': 'Cerrar'
      ,'cart.view': 'Ver carrito'
      ,'cart.title': 'Tu carrito'
      ,'cart.empty': 'Tu carrito está vacío.'
      ,'cart.remove': 'Quitar'
      ,'cart.subtotal': 'Subtotal de platos y extras'
      ,'cart.delivery': 'Tarifa de entrega'
      ,'cart.tax': 'Impuesto'
      ,'cart.total': 'Total'
      ,'cart.pendingTotal': 'Importe provisional (impuesto pendiente)'
      ,'tax.pending': 'El restaurante no ha confirmado la tasa de impuesto. El pago no está disponible.'
      ,'tax.legacy': 'Este pedido anterior no tiene un desglose de impuesto. Se conserva el importe original.'
      ,'cart.summary': 'artículos'
      ,'cart.summary.one': 'artículo'
      ,'cart.status': 'Estado del pedido'
      ,'checkout.name': 'Nombre'
      ,'checkout.phone': 'Teléfono'
      ,'checkout.email': 'Correo electrónico'
      ,'checkout.address': 'Dirección de entrega'
      ,'checkout.postal': 'Código postal de entrega'
      ,'checkout.time': 'Hora programada (en blanco: lo antes posible)'
      ,'checkout.preorderNote': 'Hora local del restaurante; al menos {lead} minutos, hasta {days} días.'
      ,'checkout.deliveryFee': 'Tarifa de entrega: {fee} {currency} (se incluye al pagar)'
      ,'checkout.payment': 'Forma de pago'
      ,'checkout.noPayment': 'No hay forma de pago disponible'
      ,'checkout.unavailable': 'No se puede finalizar el pedido ahora.'
      ,'product.quantity': 'Cantidad'
      ,'product.notes': 'Notas'
      ,'product.notesHint': 'Escribe aquí sin pollo, sin arroz u otras peticiones. Usa Agregar para los extras. Los cambios requieren confirmación, no reducen el precio automáticamente y las alergias requieren contacto directo.'
      ,'product.customize': 'Cantidad / Peticiones'
      ,'product.addon': 'Agregar'
      ,'product.addonChoice': 'Elige un extra'
      ,'product.removeAddon': 'Quitar extra'
      ,'product.required': 'Elige las opciones obligatorias.'
      ,'product.options': 'Elegir opciones'
      ,'button.soldOut': 'Agotado'
      ,'label.map': 'Mapa'
      ,'link.map': 'Cómo llegar'
      ,'nav.label': 'Navegación principal'
      ,'category.label': 'Categorías del menú'
      ,'language.label': 'Idioma'
      ,'plugin.admin': 'Administración: activa el plugin de pedidos del restaurante.'
      ,'error.request': 'No se pudo completar. Inténtalo de nuevo.'
      ,'image.placeholder': 'Sin foto'
      ,'hero.noPhoto': 'Aún no hay foto real de la fachada'
      ,'cart.state.open': 'Listo para pagar'
      ,'cart.state.checkout_started': 'Esperando el pago'
      ,'cart.state.paid': 'Pagado'
      ,'cart.state.ticketed': 'En preparación'
      ,'ticket.state.new': 'Recibido'
      ,'ticket.state.accepted': 'Aceptado'
      ,'ticket.state.preparing': 'En preparación'
      ,'ticket.state.ready': 'Listo'
      ,'ticket.state.completed': 'Completado'
      ,'ticket.state.cancelled': 'Cancelado'
    }
  };

  const supportedLocales = ['en-US', 'zh-CN', 'es-US'];
  const savedLocale = localStorage.getItem('restaurant_locale');
  let hasLanguageChoice = supportedLocales.includes(savedLocale);
  const state = {
    locale: hasLanguageChoice ? savedLocale : 'en-US',
    serviceMode: localStorage.getItem('restaurant_service_mode') === 'delivery' ? 'delivery' : 'pickup',
    storefront: null,
    menu: null,
    category: 'all',
    menuSection: 'all',
    query: '',
    cart: null,
    token: localStorage.getItem('restaurant_cart_token') || '',
    currentProduct: null,
    editingItemId: null,
    productNotes: {},
    quotePending: false
  };

  Object.assign(i18n['zh-CN'], {'cart.edit': '修改', 'cart.save': '保存修改', 'cart.increase': '增加数量', 'cart.decrease': '减少数量', 'ordering.offline': '在线付款暂不可用，您仍可查看菜单；请联系门店确认接单。', 'product.notesHint': '不要鸡肉、不要米饭等请写在备注里。特殊变更需门店确认，不自动减价，过敏需求请直接联系门店。'});
  Object.assign(i18n['en-US'], {'cart.edit': 'Edit', 'cart.save': 'Save changes', 'cart.increase': 'Increase quantity', 'cart.decrease': 'Decrease quantity', 'ordering.offline': 'Online payment is currently unavailable. You can still browse the menu; contact the restaurant to order.', 'product.notesHint': 'Write requests such as no chicken or no rice here. Changes require restaurant confirmation, do not automatically reduce the price, and allergies need direct contact.'});
  Object.assign(i18n['es-US'], {'cart.edit': 'Editar', 'cart.save': 'Guardar cambios', 'cart.increase': 'Aumentar cantidad', 'cart.decrease': 'Reducir cantidad', 'ordering.offline': 'El pago en línea no está disponible. Puedes consultar el menú; contacta al restaurante para pedir.', 'product.notesHint': 'Escribe aquí sin pollo, sin arroz u otras peticiones. Los cambios requieren confirmación, no reducen el precio automáticamente y las alergias requieren contacto directo.'});

  const t = (key) => (i18n[state.locale] && i18n[state.locale][key]) || i18n['zh-CN'][key] || key;
  i18n['zh-CN']['menuSection.all_day'] = '全天餐';
  i18n['zh-CN']['section.menu.kicker'] = '完整菜单';
  i18n['en-US']['menuSection.all_day'] = 'All-day menu';
  i18n['es-US']['menuSection.all_day'] = 'Menú todo el día';
  const money = (item) => `${item.price || '0.00'} ${item.currency || ''}`.trim();
  const cartMoney = (cart) => `${cart.total || '0.00'} ${cart.currency || ''}`.trim();
  const csrf = document.querySelector('[data-checkout-form] input[name="_csrf"]')?.value || '';

  function setText(selector, value) {
    document.querySelectorAll(selector).forEach((node) => {
      node.textContent = value || '';
    });
  }

  function applyI18n() {
    document.documentElement.lang = state.locale;
    document.querySelectorAll('[data-i18n]').forEach((node) => {
      node.textContent = t(node.dataset.i18n);
    });
    document.querySelectorAll('[data-i18n-aria]').forEach((node) => {
      node.setAttribute('aria-label', t(node.dataset.i18nAria));
    });
    const search = document.querySelector('[data-search-input]');
    if (search) search.placeholder = t('search.placeholder');
    document.querySelectorAll('[data-lang]').forEach((button) => {
      button.classList.toggle('is-active', button.dataset.lang === state.locale);
      button.setAttribute('aria-pressed', String(button.dataset.lang === state.locale));
    });
  }

  async function loadJson(url) {
    const response = await fetch(url, {headers: {'Accept': 'application/json'}});
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    if (!data || data.ok === false) throw new Error('Invalid payload');
    return data;
  }

  async function postJson(url, fields) {
    const response = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded'},
      body: new URLSearchParams({...fields, _csrf: csrf})
    });
    const data = await response.json();
    if (!response.ok || data.ok === false) throw new Error(data.error || t('error.request'));
    return data;
  }

  function notify(message) {
    setText('[data-live]', message);
  }

  async function loadCart() {
    if (!state.token) return;
    const mode = state.serviceMode;
    const token = state.token;
    const url = new URL(app.dataset.cartApi, location.href);
    url.searchParams.set('service_mode', mode);
    state.quotePending = true;
    renderCart();
    const response = await fetch(url, {
      cache: 'no-store',
      headers: {'Accept': 'application/json', 'Authorization': `Bearer ${state.token}`}
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const cart = await response.json();
    if (!cart?.ok) throw new Error('Invalid cart payload');
    if (mode !== state.serviceMode || token !== state.token) return;
    state.cart = cart;
    state.quotePending = false;
    await refreshProviders();
    renderCart();
  }

  async function refreshProviders() {
    if (!state.cart?.count || !state.storefront) return;
    try {
      const data = await loadJson(`${app.dataset.storefrontApi}?locale=${encodeURIComponent(state.locale)}&currency=${encodeURIComponent(state.cart.currency)}`);
      state.storefront.payment_providers = data.payment_providers || [];
      state.storefront.ordering = data.ordering;
    } catch (_) {
      state.storefront.payment_providers = [];
    }
    renderStorefront();
  }

  async function load() {
    applyI18n();
    notify('');
    try {
      const requestedLocale = state.locale;
      const useDefaultLocale = !hasLanguageChoice;
      const query = useDefaultLocale ? '' : `?locale=${encodeURIComponent(state.locale)}`;
      let [storefront, menu] = await Promise.all([
        loadJson(`${app.dataset.storefrontApi}${query}`),
        loadJson(`${app.dataset.menuApi}${query}`)
      ]);
      if (state.locale !== requestedLocale || (useDefaultLocale && hasLanguageChoice)) return;
      if (useDefaultLocale) {
        state.locale = supportedLocales.includes(storefront.default_locale) ? storefront.default_locale : 'en-US';
        if (storefront.locale !== state.locale) {
          const preferredQuery = `?locale=${encodeURIComponent(state.locale)}`;
          [storefront, menu] = await Promise.all([
            loadJson(`${app.dataset.storefrontApi}${preferredQuery}`),
            loadJson(`${app.dataset.menuApi}${preferredQuery}`)
          ]);
          if (hasLanguageChoice) return;
        }
      }
      state.storefront = storefront;
      state.menu = menu;
      render();
      await loadCart().catch(() => notify(t('error.request')));
    } catch (error) {
      renderFallback(error);
    }
  }

  function render() {
    applyI18n();
    renderStorefront();
    renderMenuSections();
    renderCategories();
    renderProducts();
    renderGallery();
    renderSections();
    renderCart();
    updateSeo();
  }

  function renderStorefront() {
    const data = state.storefront || {};
    const restaurant = data.restaurant || {};
    const hero = data.hero || {};
    const name = restaurant.name || app.dataset.fallbackName || 'Daiying Restaurant';
    const logo = app.dataset.siteLogo || restaurant.logo_url || app.dataset.fallbackLogo || '';
    const heroImage = hero.image_url || app.dataset.fallbackStorefront || '';
    const statusKey = `status.${restaurant.status || 'closed'}`;

    setText('[data-restaurant-name]', name);
    setText('[data-hero-title]', hero.headline || name);
    setText('[data-hero-subtitle]', hero.subtitle || '');
    setText('[data-status]', t(statusKey));
    setText('[data-hours]', restaurant.today_hours || '');
    setText('[data-address]', restaurant.address || '');
    const onlineNotice = document.querySelector('[data-online-notice]');
    if (onlineNotice) {
      onlineNotice.hidden = data.ordering?.online_ready !== false;
      onlineNotice.textContent = t('ordering.offline');
    }
    setText('[data-about-title]', (data.about && data.about.title) || t('section.about.kicker'));
    setText('[data-about-body]', (data.about && data.about.body) || '');

    const phone = document.querySelector('[data-phone]');
    if (phone) {
      phone.textContent = restaurant.phone || '';
      phone.href = restaurant.phone ? `tel:${restaurant.phone}` : '#';
    }

    const logoNode = document.querySelector('[data-logo]');
    if (logoNode && logo) {
      logoNode.innerHTML = `<img src="${escapeHtml(logo)}" alt="">`;
    } else if (logoNode) {
      logoNode.textContent = name.slice(0, 1);
    }

    const heroNode = document.querySelector('[data-hero-image]');
    const photoNote = document.querySelector('[data-hero-placeholder]');
    if (photoNote) photoNote.hidden = false;
    if (heroNode) heroNode.hidden = true;
    if (heroNode && heroImage) {
      heroNode.onload = () => {
        heroNode.hidden = false;
        if (photoNote) photoNote.hidden = true;
      };
      heroNode.onerror = () => {
        heroNode.hidden = true;
        if (photoNote) photoNote.hidden = false;
      };
      heroNode.src = heroImage;
      heroNode.alt = name;
      if (heroNode.complete && heroNode.naturalWidth > 0) heroNode.onload();
    }
    const desktopFocus = /^\d{1,3}% \d{1,3}%$/.test(hero.desktop_focal_point || '') ? hero.desktop_focal_point : '';
    const mobileFocus = /^\d{1,3}% \d{1,3}%$/.test(hero.mobile_focal_point || '') ? hero.mobile_focal_point : '';
    if (desktopFocus) app.style.setProperty('--rs-hero-focus-desktop', desktopFocus);
    if (mobileFocus) app.style.setProperty('--rs-hero-focus-mobile', mobileFocus);

    if (restaurant.service_modes?.[state.serviceMode]?.available !== true && restaurant.service_modes?.pickup?.available === true) {
      state.serviceMode = 'pickup';
      localStorage.setItem('restaurant_service_mode', state.serviceMode);
    }

    document.querySelectorAll('[data-service-mode]').forEach((button) => {
      const mode = button.dataset.serviceMode;
      button.classList.toggle('is-active', mode === state.serviceMode);
      const modeState = restaurant.service_modes && restaurant.service_modes[mode];
      if (!state.storefront || modeState?.available !== true || !(['open', 'closing_soon'].includes(restaurant.status) || restaurant.preorder?.available === true)) {
        button.setAttribute('aria-disabled', 'true');
        button.disabled = true;
      } else {
        button.removeAttribute('aria-disabled');
        button.disabled = false;
      }
    });
    const selected = restaurant.service_modes && restaurant.service_modes[state.serviceMode];
    setText('[data-service-note]', selected && selected.available === false ? (selected.reason || t(`status.${state.serviceMode}_unavailable`)) : (restaurant.status_reason || ''));
    const address = restaurant.address || '';
    const map = document.querySelector('[data-map]');
    if (map) {
      map.href = address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}` : '#';
      map.hidden = !address;
    }
    const addressField = document.querySelector('[data-delivery-address]');
    if (addressField) {
      addressField.hidden = state.serviceMode !== 'delivery';
      addressField.querySelectorAll('input').forEach((input) => { input.required = state.serviceMode === 'delivery'; });
    }
    const checkoutMode = document.querySelector('[data-checkout-mode]');
    if (checkoutMode) checkoutMode.value = state.serviceMode;
    const preorderField = document.querySelector('[data-preorder-field]');
    if (preorderField) preorderField.hidden = restaurant.preorder?.available !== true;
    const preorderNote = document.querySelector('[data-preorder-note]');
    if (preorderNote) preorderNote.textContent = restaurant.preorder?.available ? t('checkout.preorderNote').replace('{lead}', restaurant.preorder.lead_minutes).replace('{days}', restaurant.preorder.max_days) + ` ${restaurant.timezone || ''}` : '';
    const deliveryFee = document.querySelector('[data-delivery-fee]');
    if (deliveryFee) deliveryFee.textContent = state.serviceMode === 'delivery' ? t('checkout.deliveryFee').replace('{fee}', restaurant.service_modes?.delivery?.fee || '0.00').replace('{currency}', state.cart?.currency || state.menu?.items?.[0]?.currency || '') : '';
  }

  function inMenuSection(item) {
    return state.menuSection === 'all' || item.menu_section === state.menuSection;
  }

  function renderMenuSections() {
    const wrap = document.querySelector('[data-menu-section-list]');
    if (!wrap) return;
    const items = (state.menu && state.menu.items) || [];
    const sections = ['all', 'lunch', 'dinner', 'kids'].filter((section) => section === 'all' || items.some((item) => item.menu_section === section));
    wrap.innerHTML = sections.map((section) => `<button type="button" data-menu-section="${section}"${section === state.menuSection ? ' class="is-active" aria-current="true"' : ''}>${escapeHtml(t(`menuSection.${section}`))}</button>`).join('');
  }

  function renderCategories() {
    const sectionItems = ((state.menu && state.menu.items) || []).filter(inMenuSection);
    const available = new Set(sectionItems.map((item) => item.category_slug));
    const categories = [{slug: 'all', name: t('section.menu.title'), count: sectionItems.length}].concat(((state.menu && state.menu.categories) || []).filter((category) => available.has(category.slug)).map((category) => ({...category, count: sectionItems.filter((item) => item.category_slug === category.slug).length})));
    const wrap = document.querySelector('[data-category-list]');
    if (!wrap) return;
    wrap.innerHTML = categories.map((category) => {
      const active = category.slug === state.category ? ' class="is-active"' : '';
      return `<button type="button" data-category="${escapeHtml(category.slug)}"${active}>${escapeHtml(category.name)} <small>${category.count}</small></button>`;
    }).join('');
    const picker = document.querySelector('[data-category-select]');
    if (picker) {
      picker.innerHTML = categories.map((category) => `<option value="${escapeHtml(category.slug)}">${escapeHtml(category.name)} (${category.count})</option>`).join('');
      picker.value = state.category;
    }
  }

  function renderProducts() {
    const terms = state.query.trim().normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase().split(/\s+/).filter(Boolean);
    const all = ((state.menu && state.menu.items) || []).filter((item) => {
      const inCategory = state.category === 'all' || item.category_slug === state.category;
      const searchable = `${item.name || ''} ${item.description || ''} ${item.category || ''} ${(item.tags || []).join(' ')} ${(item.search_terms || []).join(' ')}`.normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
      const inSearch = terms.every((term) => searchable.includes(term));
      return inMenuSection(item) && inCategory && inSearch;
    });
    const featured = ((state.menu && state.menu.featured) || []).slice(0, 6);
    renderList('[data-featured-list]', featured, t('empty.featured'));
    renderList('[data-menu-list]', all, state.query ? t('empty.search') : t('empty.menu'));
    setText('[data-search-count]', t('search.results').replace('{count}', String(all.length)));
  }

  function renderList(selector, items, emptyText) {
    const wrap = document.querySelector(selector);
    const template = document.getElementById('rs-product-card');
    if (!wrap || !template) return;
    wrap.innerHTML = '';
    if (!items || items.length === 0) {
      wrap.innerHTML = `<p class="rs-empty">${escapeHtml(emptyText)}</p>`;
      return;
    }
    items.forEach((item) => {
      const node = template.content.firstElementChild.cloneNode(true);
      const img = node.querySelector('img');
      const figure = node.querySelector('figure');
      const title = node.querySelector('h3');
      const desc = node.querySelector('.rs-desc');
      const price = node.querySelector('.rs-price');
      const tags = node.querySelector('.rs-tags');
      const button = node.querySelector('.rs-add');
      const note = node.querySelector('[data-card-notes]');
      if (note) {
        note.dataset.cardNotes = String(item.id);
        note.value = state.productNotes[item.id] || '';
        note.setAttribute('aria-label', `${item.name} · ${t('product.notes')}`);
        node.querySelector('.rs-card-notes span').textContent = t('product.notes');
      }
      if (img) {
        if (item.image_url) {
          img.src = item.image_url;
          img.alt = item.name || '';
          img.onerror = () => { img.hidden = true; figure?.classList.add('is-placeholder'); };
        } else {
          img.hidden = true;
          figure && figure.classList.add('is-placeholder');
        }
      }
      if (figure) figure.dataset.placeholder = t('image.placeholder');
      if (title) title.textContent = item.name || '';
      if (desc) desc.textContent = item.description || item.category || '';
      if (price) price.textContent = money(item);
      if (tags) tags.innerHTML = `<span class="rs-meal-badge">${escapeHtml(t(`menuSection.${item.menu_section || 'all_day'}`))}</span>` + (item.tags || []).slice(0, 3).map((tag) => `<span>${escapeHtml(tag)}</span>`).join('');
      if (button) {
        const available = item.ordering?.available === true && canOrder();
        button.textContent = item.status === 'sold_out' ? t('button.soldOut') : available ? (visibleModifiers(item).length ? t('product.options') : t('button.add')) : t('button.unavailable');
        button.disabled = !available;
        button.dataset.productId = String(item.id);
        if (item.status === 'sold_out') node.classList.add('is-sold-out');
      }
      wrap.appendChild(node);
    });
  }

  function renderGallery() {
    const wrap = document.querySelector('[data-gallery]');
    if (!wrap) return;
    const items = (state.storefront && state.storefront.gallery) || [];
    wrap.innerHTML = items.slice(0, 8).map((item) => `<figure><img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.alt || '')}" loading="lazy"><figcaption>${escapeHtml(item.alt || '')}</figcaption></figure>`).join('');
  }

  function renderSections() {
    const flags = {
      favorites: app.dataset.showFavorites !== 'false',
      menu: app.dataset.showMenu !== 'false',
      gallery: app.dataset.showGallery !== 'false',
      about: app.dataset.showAbout !== 'false',
      location: app.dataset.showLocation !== 'false'
    };
    Object.keys(flags).forEach((key) => {
      document.querySelectorAll(`[data-section="${key}"]`).forEach((section) => {
        const hasContent = key === 'gallery' ? (state.storefront?.gallery || []).length > 0
          : key === 'about' ? Boolean(state.storefront?.about?.body)
          : key === 'location' ? Boolean(state.storefront?.restaurant?.address)
          : key === 'favorites' ? Boolean(state.menu?.featured?.length)
          : true;
        section.hidden = !flags[key] || !hasContent;
        document.querySelectorAll(`[data-nav-section="${key}"]`).forEach((link) => { link.hidden = section.hidden; });
      });
    });
  }

  function canOrder() {
    const restaurant = state.storefront?.restaurant || {};
    return (['open', 'closing_soon'].includes(restaurant.status) || restaurant.preorder?.available === true) && restaurant.service_modes?.[state.serviceMode]?.available === true;
  }

  function canCheckoutTime() {
    const restaurant = state.storefront?.restaurant || {};
    return ['open', 'closing_soon'].includes(restaurant.status) || Boolean(document.querySelector('[data-fulfillment-time]')?.value);
  }

  function findProduct(productId) {
    return state.menu?.items?.find((item) => String(item.id) === String(productId));
  }

  function visibleModifiers(item) {
    return (item.modifiers || []).filter((group) => group.id !== 'special_requests');
  }

  function rememberNotes(productId, notes) {
    state.productNotes[productId] = notes;
    document.querySelectorAll('[data-card-notes]').forEach((field) => {
      if (field.dataset.cardNotes === String(productId) && field.value !== notes) field.value = notes;
    });
  }

  function renderAddedOptions(group, selectedIds) {
    const item = state.currentProduct;
    const options = item.modifiers.find((entry) => entry.id === 'paid_addons')?.options || [];
    const chosen = options.filter((option) => selectedIds.includes(option.id));
    const select = group.querySelector('[data-addon-choice]');
    select.innerHTML = `<option value="">${escapeHtml(t('product.addonChoice'))}</option>` + options.map((option) => `<option value="${escapeHtml(option.id)}"${selectedIds.includes(option.id) ? ' disabled' : ''}>${escapeHtml(option.name)} (+${escapeHtml(option.price)} ${escapeHtml(item.currency)})</option>`).join('');
    select.disabled = chosen.length === options.length;
    group.querySelector('[data-choose-addon]').disabled = true;
    group.querySelector('[data-chosen-addons]').innerHTML = chosen.map((option) => `<div class="rs-picked-addon"><input type="checkbox" hidden checked value="${escapeHtml(option.id)}" data-group-id="paid_addons"><span>${escapeHtml(option.name)} (+${escapeHtml(option.price)} ${escapeHtml(item.currency)})</span><button type="button" data-remove-addon="${escapeHtml(option.id)}" aria-label="${escapeHtml(t('product.removeAddon') + ' ' + option.name)}">${escapeHtml(t('product.removeAddon'))}</button></div>`).join('');
  }

  function openProduct(item, cartItem = null) {
    state.currentProduct = item;
    state.editingItemId = cartItem?.id || null;
    const detail = document.querySelector('[data-product-detail]');
    const groups = document.querySelector('[data-modifier-groups]');
    if (!detail || !groups) return;
    detail.innerHTML = `${item.image_url ? `<img class="rs-detail-image" src="${escapeHtml(item.image_url)}" alt="${escapeHtml(item.name)}">` : ''}<h2 id="product-title">${escapeHtml(item.name)}</h2><p>${escapeHtml(item.description || '')}</p><span class="rs-meal-badge">${escapeHtml(t(`menuSection.${item.menu_section || 'all_day'}`))}</span> <strong>${escapeHtml(money(item))}</strong>`;
    groups.innerHTML = visibleModifiers(item).map((group) => {
      if (group.id === 'paid_addons') {
        return `<fieldset data-modifier-group data-paid-addons data-min="${Number(group.min || 0)}" data-max="${Number(group.max || 1)}"><legend>${escapeHtml(t('product.addon'))}</legend><div class="rs-addon-picker"><label><span>${escapeHtml(t('product.addonChoice'))}</span><select data-addon-choice></select></label><button type="button" data-choose-addon disabled>${escapeHtml(t('product.addon'))}</button></div><div data-chosen-addons></div></fieldset>`;
      }
      const multi = Number(group.max || 1) > 1 || Number(group.min || 0) === 0;
      const type = multi ? 'checkbox' : 'radio';
      const options = (group.options || []).map((option) => `<label class="rs-option"><input type="${type}" name="modifier-${escapeHtml(group.id)}" value="${escapeHtml(option.id)}" data-group-id="${escapeHtml(group.id)}"><span>${escapeHtml(option.name)}</span><span>+${escapeHtml(option.price || '0.00')} ${escapeHtml(item.currency || '')}</span></label>`).join('');
      return `<fieldset data-modifier-group data-min="${Number(group.min || 0)}" data-max="${Number(group.max || 1)}"><legend>${escapeHtml(group.name || '')}</legend>${options}</fieldset>`;
    }).join('');
    const form = document.querySelector('[data-product-form]');
    form.reset();
    form.elements.notes.value = cartItem ? cartItem.notes || '' : state.productNotes[item.id] || '';
    form.elements.quantity.value = String(cartItem?.quantity || 1);
    form.querySelector('button[type="submit"]').textContent = t(cartItem ? 'cart.save' : 'button.add');
    const addons = form.querySelector('[data-paid-addons]');
    if (addons) renderAddedOptions(addons, (cartItem?.modifiers || []).filter((option) => option.group_id === 'paid_addons').map((option) => option.option_id));
    form.querySelectorAll('input[data-group-id]').forEach((input) => {
      if ((cartItem?.modifiers || []).some((option) => option.group_id === input.dataset.groupId && option.option_id === input.value)) input.checked = true;
    });
    document.querySelector('[data-product-dialog]').showModal();
  }

  async function addProduct(item, quantity = 1, modifiers = [], notes = '') {
    const cart = await postJson(app.dataset.cartAddApi, {
      token: state.token,
      menu_item_id: String(item.id),
      quantity: String(quantity),
      modifiers: JSON.stringify(modifiers),
      notes
    });
    state.token = cart.cart_token || state.token;
    localStorage.setItem('restaurant_cart_token', state.token);
    state.cart = cart;
    rememberNotes(item.id, '');
    await loadCart();
    notify(`${item.name} · ${t('button.add')}`);
  }

  function renderOrderReceipt(receipt) {
    const section = document.querySelector('[data-order-receipt]');
    const available = Number.isInteger(receipt?.order_id) && receipt.order_id > 0;
    document.querySelectorAll('[data-order-entry]').forEach((button) => { button.hidden = !available; });
    if (!section) return;
    section.hidden = !available;
    if (!available) {
      section.replaceChildren();
      return;
    }
    const stateLabel = receipt.completed ? t('receipt.completed') : receipt.pickup_eligible ? t('receipt.ready') : t('receipt.waiting');
    section.innerHTML = `<h3>${escapeHtml(t('receipt.title'))}</h3><p class="rs-order-number">${escapeHtml(t('receipt.number'))} <strong>#${receipt.order_id}</strong></p>${receipt.ticket_number ? `<p>${escapeHtml(t('receipt.ticket'))}: ${escapeHtml(receipt.ticket_number)}</p>` : ''}<p>${escapeHtml(t(`receipt.payment.${receipt.payment_status}`))} · ${escapeHtml(receipt.total)} ${escapeHtml(receipt.currency)}</p><p>${escapeHtml(t(`mode.${receipt.service_mode}`))}${receipt.ticket_status ? ` · ${escapeHtml(t(`ticket.state.${receipt.ticket_status}`))}` : ''}${receipt.due_local ? ` · ${escapeHtml(receipt.due_local)}` : ''}</p><p><strong>${escapeHtml(stateLabel)}</strong></p><button type="button" data-refresh-order>${escapeHtml(t('receipt.refresh'))}</button><p class="rs-receipt-hint">${escapeHtml(t('receipt.hint'))}</p>`;
  }

  function renderCart() {
    const cart = state.cart;
    renderOrderReceipt(cart?.order_receipt);
    const count = Number(cart?.count || 0);
    const bar = document.querySelector('[data-cart-bar]');
    if (bar) bar.hidden = count === 0;
    const headerCart = document.querySelector('.rs-header-cart');
    if (headerCart) headerCart.hidden = count === 0;
    setText('[data-cart-count]', String(count));
    setText('[data-cart-summary]', `${count} ${t(count === 1 ? 'cart.summary.one' : 'cart.summary')} · ${cartMoney(cart || {})}`);
    const list = document.querySelector('[data-cart-items]');
    if (list) {
      list.innerHTML = count === 0 ? `<p>${escapeHtml(t('cart.empty'))}</p>` : (cart.items || []).map((item) => {
        const product = findProduct(item.menu_item_id);
        const selected = (item.modifiers || []).map((modifier) => {
          const group = product?.modifiers?.find((entry) => entry.id === modifier.group_id);
          const name = group?.options?.find((option) => option.id === modifier.option_id)?.name || modifier.name || '';
          return name && Number(modifier.price) > 0 ? `${name} (+${modifier.price} ${item.currency || cart.currency || ''})` : name;
        }).filter(Boolean).join(', ');
        const lineAmount = `${(Number(item.line_total_minor || 0) / 100).toFixed(2)} ${item.currency || cart.currency || ''}`;
        const editable = cart.cart?.status === 'open';
        const controls = editable ? `<div class="rs-cart-controls"><button type="button" data-cart-step="-1" data-cart-item="${Number(item.id)}" aria-label="${escapeHtml(t('cart.decrease') + ' · ' + (product?.name || item.item_name))}" ${Number(item.quantity) <= 1 ? 'disabled' : ''}>−</button><span>${Number(item.quantity)}</span><button type="button" data-cart-step="1" data-cart-item="${Number(item.id)}" aria-label="${escapeHtml(t('cart.increase') + ' · ' + (product?.name || item.item_name))}" ${Number(item.quantity) >= 99 ? 'disabled' : ''}>+</button>${product ? `<button type="button" data-edit-item="${Number(item.id)}">${escapeHtml(t('cart.edit'))}</button>` : ''}<button type="button" data-remove-item="${Number(item.id)}">${escapeHtml(t('cart.remove'))}</button></div>` : '';
        return `<div class="rs-cart-item"><span>${escapeHtml(product?.name || item.item_name)} × ${Number(item.quantity) || 1}${product ? `<small>${escapeHtml(t(`menuSection.${product.menu_section || 'all_day'}`))}</small>` : ''}${selected ? `<small>${escapeHtml(selected)}</small>` : ''}${item.notes ? `<small>${escapeHtml(t('product.notes'))}: ${escapeHtml(item.notes)}</small>` : ''}<small>${escapeHtml(lineAmount)}</small></span>${controls}</div>`;
      }).join('');
      if (cart?.cart?.status && cart.cart.status !== 'open') list.innerHTML += `<p>${escapeHtml(t('cart.status'))}: ${escapeHtml(t(`cart.state.${cart.cart.status}`))}</p>`;
      if (cart?.order_status?.ticket_status) list.innerHTML += `<p>${escapeHtml(t(`ticket.state.${cart.order_status.ticket_status}`))}</p>`;
    }
    setText('[data-cart-total]', cartMoney(cart || {}));
    const pricing = document.querySelector('[data-cart-pricing]');
    const taxPending = cart?.cart?.status === 'open' && cart?.tax?.configured !== true;
    setText('[data-pricing-total-label]', t(taxPending ? 'cart.pendingTotal' : 'cart.total'));
    if (pricing) {
      const currency = cart?.currency || '';
      const taxLine = cart?.tax?.configured === true ? `${t('cart.tax')} (${cart.tax.rate_percent}%) · ${cart.tax_amount} ${currency}` : t(taxPending ? 'tax.pending' : 'tax.legacy');
      pricing.innerHTML = count === 0 ? '' : `<p>${escapeHtml(t('cart.subtotal'))} · ${escapeHtml(cart.subtotal)} ${escapeHtml(currency)}</p><p>${escapeHtml(t('cart.delivery'))} · ${escapeHtml(cart.fee || '0.00')} ${escapeHtml(currency)}</p><p>${escapeHtml(taxLine)}</p>`;
    }
    const expectedTotal = document.querySelector('[data-checkout-total]');
    if (expectedTotal) expectedTotal.value = String(cart?.total_minor ?? '');
    const deliveryFee = document.querySelector('[data-delivery-fee]');
    const restaurant = state.storefront?.restaurant || {};
    if (deliveryFee && state.serviceMode === 'delivery') deliveryFee.textContent = t('checkout.deliveryFee').replace('{fee}', restaurant.service_modes?.delivery?.fee || '0.00').replace('{currency}', cart?.currency || state.menu?.items?.[0]?.currency || '');
    const token = document.querySelector('[data-cart-token]');
    if (token) token.value = state.token;
    const providers = document.querySelector('[data-payment-provider]');
    const options = state.storefront?.payment_providers || [];
    if (providers) providers.innerHTML = options.length ? options.map((provider) => `<option value="${escapeHtml(provider.id)}">${escapeHtml(provider.label)}</option>`).join('') : `<option value="">${escapeHtml(t('checkout.noPayment'))}</option>`;
    const submit = document.querySelector('[data-checkout-form] button[type="submit"]');
    if (submit) {
      submit.disabled = count === 0 || !canOrder() || !canCheckoutTime() || options.length === 0 || cart?.cart?.status !== 'open' || taxPending || state.quotePending || cart?.service_mode !== state.serviceMode;
      submit.title = submit.disabled ? t('checkout.unavailable') : '';
    }
  }

  function updateSeo() {
    const restaurant = state.storefront?.restaurant;
    if (!restaurant?.name || !restaurant.address) return;
    const description = `${restaurant.name} · ${restaurant.address}. ${restaurant.today_hours || ''}`.trim();
    document.title = `${restaurant.name} | ${t('section.menu.title')}`;
    document.querySelector('[data-seo-description]').content = description;
    document.querySelector('[data-og-title]').content = restaurant.name;
    document.querySelector('[data-og-description]').content = description;
    const canonical = `${location.origin}${location.pathname}`;
    let canonicalNode = document.querySelector('[data-seo-canonical]');
    if (!canonicalNode) {
      canonicalNode = document.createElement('link');
      canonicalNode.rel = 'canonical';
      canonicalNode.dataset.seoCanonical = '';
      document.head.appendChild(canonicalNode);
    }
    canonicalNode.href = canonical;
    let openGraphUrl = document.querySelector('[data-og-url]');
    if (!openGraphUrl) {
      openGraphUrl = document.createElement('meta');
      openGraphUrl.setAttribute('property', 'og:url');
      openGraphUrl.dataset.ogUrl = '';
      document.head.appendChild(openGraphUrl);
    }
    openGraphUrl.content = canonical;
    let schema = document.querySelector('[data-restaurant-schema]');
    if (!schema) {
      schema = document.createElement('script');
      schema.type = 'application/ld+json';
      schema.dataset.restaurantSchema = '';
      document.head.appendChild(schema);
    }
    const payload = {'@context': 'https://schema.org', '@type': 'Restaurant', name: restaurant.name, address: restaurant.address, url: canonical, hasMenu: `${canonical}#menu`};
    if (restaurant.phone) payload.telephone = restaurant.phone;
    if (state.storefront?.hero?.image_url) payload.image = new URL(state.storefront.hero.image_url, location.origin).href;
    schema.textContent = JSON.stringify(payload);
  }

  function renderFallback(error) {
    applyI18n();
    state.storefront = null;
    state.menu = null;
    setText('[data-status]', t('plugin.missing'));
    setText('[data-hero-title]', app.dataset.fallbackName || 'Daiying Restaurant');
    setText('[data-hero-subtitle]', t('plugin.admin'));
    setText('[data-about-title]', t('section.about.kicker'));
    document.querySelectorAll('[data-service-mode]').forEach((button) => {
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');
    });
    renderList('[data-menu-list]', [], t('plugin.missing'));
    renderCategories();
    renderSections();
    renderCart();
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;'
    })[char]);
  }

  document.addEventListener('click', async (event) => {
    const addOption = event.target.closest('[data-choose-addon]');
    const removeOption = event.target.closest('[data-remove-addon]');
    if (addOption || removeOption) {
      const group = (addOption || removeOption).closest('[data-paid-addons]');
      let selectedIds = Array.from(group.querySelectorAll('input:checked')).map((input) => input.value);
      if (addOption) {
        const optionId = group.querySelector('[data-addon-choice]').value;
        if (optionId && !selectedIds.includes(optionId)) selectedIds.push(optionId);
      } else {
        selectedIds = selectedIds.filter((optionId) => optionId !== removeOption.dataset.removeAddon);
      }
      renderAddedOptions(group, selectedIds);
      return;
    }
    const language = event.target.closest('[data-lang]');
    if (language) {
      if (!supportedLocales.includes(language.dataset.lang)) return;
      state.locale = language.dataset.lang;
      hasLanguageChoice = true;
      state.category = 'all';
      state.query = '';
      const search = document.querySelector('[data-search-input]');
      if (search) search.value = '';
      localStorage.setItem('restaurant_locale', state.locale);
      load();
      return;
    }
    const menuSection = event.target.closest('[data-menu-section]');
    if (menuSection) {
      state.menuSection = menuSection.dataset.menuSection || 'all';
      state.category = 'all';
      renderMenuSections();
      renderCategories();
      renderProducts();
      return;
    }
    const category = event.target.closest('[data-category]');
    if (category) {
      state.category = category.dataset.category || 'all';
      renderCategories();
      renderProducts();
      return;
    }
    const service = event.target.closest('[data-service-mode]');
    if (service && service.getAttribute('aria-disabled') !== 'true') {
      state.serviceMode = service.dataset.serviceMode || 'pickup';
      localStorage.setItem('restaurant_service_mode', state.serviceMode);
      renderStorefront();
      renderProducts();
      renderCart();
      try {
        await loadCart();
      } catch (error) {
        notify(error.message || t('error.request'));
      }
      if (service.closest('.rs-cta-row')) document.getElementById('menu')?.scrollIntoView({behavior: 'smooth'});
      return;
    }
    const add = event.target.closest('[data-product-id]');
    if (add) {
      const item = findProduct(add.dataset.productId);
      if (!item || !canOrder()) return;
      openProduct(item);
      return;
    }
    if (event.target.closest('[data-refresh-order]')) {
      try {
        await loadCart();
      } catch (error) {
        state.quotePending = false;
        notify(t('error.request'));
      }
      return;
    }
    if (event.target.closest('[data-open-cart]')) {
      renderCart();
      document.querySelector('[data-cart-dialog]').showModal();
      if (state.cart?.order_receipt) {
        try {
          await loadCart();
        } catch (error) {
          state.quotePending = false;
          notify(t('error.request'));
        }
      }
      return;
    }
    const remove = event.target.closest('[data-remove-item]');
    const edit = event.target.closest('[data-edit-item]');
    const step = event.target.closest('[data-cart-step]');
    if ((edit || step) && state.cart?.cart?.status === 'open') {
      const cartItem = state.cart.items.find((entry) => Number(entry.id) === Number(edit?.dataset.editItem || step?.dataset.cartItem));
      if (!cartItem) return;
      if (edit) {
        const product = findProduct(cartItem.menu_item_id);
        if (product) openProduct(product, cartItem);
      } else {
        step.disabled = true;
        try {
          state.cart = await postJson(app.dataset.cartUpdateApi, {token: state.token, item_id: String(cartItem.id), quantity: String(Number(cartItem.quantity) + Number(step.dataset.cartStep)), modifiers: JSON.stringify(cartItem.modifiers || []), notes: cartItem.notes || ''});
          await loadCart();
        } catch (error) {
          notify(error.message || t('error.request'));
          step.disabled = false;
        }
      }
      return;
    }
    if (remove && state.token) {
      remove.disabled = true;
      try {
        state.cart = await postJson(app.dataset.cartRemoveApi, {token: state.token, item_id: remove.dataset.removeItem});
        await loadCart();
      } catch (error) {
        notify(error.message || t('error.request'));
        remove.disabled = false;
      }
    }
  });

  document.addEventListener('change', (event) => {
    if (event.target.matches('[data-addon-choice]')) {
      event.target.closest('[data-paid-addons]').querySelector('[data-choose-addon]').disabled = !event.target.value;
    }
  });

  document.addEventListener('input', (event) => {
    if (event.target.matches('[data-card-notes]') && findProduct(event.target.dataset.cardNotes)) {
      rememberNotes(event.target.dataset.cardNotes, event.target.value);
    } else if (event.target.matches('[data-product-form] textarea[name="notes"]') && state.currentProduct && !state.editingItemId) {
      rememberNotes(state.currentProduct.id, event.target.value);
    }
  });

  document.querySelector('[data-product-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const item = state.currentProduct;
    if (!item || !canOrder()) return;
    const selected = [];
    for (const group of form.querySelectorAll('[data-modifier-group]')) {
      const checked = Array.from(group.querySelectorAll('input:checked'));
      if (checked.length < Number(group.dataset.min) || checked.length > Number(group.dataset.max)) {
        notify(t('product.required'));
        return;
      }
      checked.forEach((option) => selected.push({group_id: option.dataset.groupId, option_id: option.value}));
    }
    if (state.editingItemId) {
      const original = state.cart?.items?.find((entry) => Number(entry.id) === Number(state.editingItemId));
      (original?.modifiers || []).filter((option) => option.group_id === 'special_requests').forEach((option) => selected.push({group_id: option.group_id, option_id: option.option_id}));
    }
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    try {
      if (state.editingItemId) {
        state.cart = await postJson(app.dataset.cartUpdateApi, {token: state.token, item_id: String(state.editingItemId), quantity: form.elements.quantity.value, modifiers: JSON.stringify(selected), notes: form.elements.notes.value});
        await loadCart();
        notify(t('cart.save'));
      } else {
        await addProduct(item, Number(form.elements.quantity.value), selected, form.elements.notes.value);
      }
      document.querySelector('[data-product-dialog]').close();
    } catch (error) {
      notify(error.message || t('error.request'));
    } finally {
      button.disabled = false;
    }
  });

  document.querySelector('[data-checkout-form]')?.addEventListener('submit', (event) => {
    if (!canOrder() || !canCheckoutTime() || !state.token || !state.storefront?.payment_providers?.length || state.quotePending || state.cart?.tax?.configured !== true || state.cart?.service_mode !== state.serviceMode) {
      event.preventDefault();
      notify(t('checkout.unavailable'));
    }
  });

  const search = document.querySelector('[data-search-input]');
  document.querySelector('[data-fulfillment-time]')?.addEventListener('input', renderCart);
  if (search) {
    search.addEventListener('input', () => {
      state.query = search.value || '';
      if (state.query.trim()) {
        state.category = 'all';
        state.menuSection = 'all';
        renderMenuSections();
        renderCategories();
      }
      renderProducts();
    });
  }
  document.querySelector('[data-category-select]')?.addEventListener('change', (event) => {
    state.category = event.target.value || 'all';
    renderCategories();
    renderProducts();
  });

  load();
})();
