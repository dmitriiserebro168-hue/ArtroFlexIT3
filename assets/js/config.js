/*
 * НАСТРОЙКИ ЛЕНДИНГА — единственное место для правки цены, скидки, названий и утверждений.
 * Подробная инструкция: README.md, раздел «Как менять цену, скидку, таймер, остаток и тексты».
 *
 * Таймер и остаток упаковок в рабочем режиме берутся с сервера (api/config.php → api/offer.php).
 * Значения ниже — только запасной вариант, если сервер не ответил, и для демо-режима.
 */
window.LANDING_CONFIG = {
  // "draft"      — черновик: показываются ВСЕ утверждения из концепции с жёлтой меткой «da verificare»
  //                и видны плейсхолдеры [В КВАДРАТНЫХ СКОБКАХ].
  // "production" — показываются только утверждения, у которых в approvedClaims стоит true.
  mode: "draft",

  landingVersion: "tsl-it-1.0",
  offerId: "208",

  // Тексты, подставляемые в элементы data-t="..."
  text: {
    productName: "Artroflex Active",
    productCategory: "un integratore alimentare in capsule",
    partnerName: "Neo Elementum OÜ",       // партнёрская сеть (Webvork), получает заявки
    sellerName: "[RAGIONE SOCIALE VENDITORE]",
    sellerAddress: "[INDIRIZZO SEDE LEGALE]",
    sellerVat: "[PARTITA IVA]",
    sellerPhone: "[TELEFONO]",
    sellerEmail: "[EMAIL]",
    manufacturer: "Developed by Geberich",
    units: "30 capsule",                     // при 3 капсулах в день = 10 дней
    packs: "1 confezione",                  // количество упаковок в предложении
    deliveryTime: "1–2 giorni",
    deliveryArea: "[ZONE DI CONSEGNA]",
    refundDays: "[X]",
    callbackTime: "10 minuti"               // используется только при approvedClaims.call10min
  },

  // Цены в евро. null — цена не указана (в черновике будет «€ —»).
  price: {
    old: 78,            // цена сравнения (наименьшая за 30 дней до скидки — Codice del Consumo, art. 17-bis)
    current: 39,        // актуальная цена
    discountPercent: 50,
    shipping: null,     // 0 = доставка бесплатна
    extraFees: null,    // иные обязательные платежи (напр. контрассегно); null — нет
    afterExpiry: null   // цена после окончания акции; null — цена скрывается, условия сообщает оператор
  },

  offer: {
    // Запасная дата окончания (Europe/Rome). Рабочее значение — в api/config.php.
    fallbackEndsAt: "2026-10-31T23:59:59+01:00",
    fallbackStock: 17,
    // Персональная скидка: действует N часов с ПЕРВОГО захода посетителя (запоминается в браузере,
    // при повторном заходе таймер продолжает отсчёт, а не начинается заново). 0 — общая дата из api/config.php.
    personalHours: 5,
    // Что делать после окончания акции: "end" — скрыть скидку, таймер и остаток, показать сообщение.
    onExpire: "end"
  },

  // Утверждения из концепции (раздел 14 ТЗ). В режиме production показываются только true.
  // Ставить true только после заполнения карточки в docs/claims.md.
  approvedClaims: {
    heroTestimonial: true,   // H1 «Cammino liberamente, senza dolore alle ginocchia, già dopo due settimane»
    chondroitin: true,       // «Condroitina → la cartilagine si rigenera → movimenti senza scricchiolii»
    problemWorsens: true,    // «la situazione può peggiorare»
    stats: true,             // 12 000 clienti / 15 anni / 98% riordini
    discount: true,          // скидка 50%: 78 € → 39 €
    stock: true,             // «Restano 17 confezioni»
    timer: true,             // таймер окончания акции
    call10min: true,         // «Ti richiamiamo entro 10 minuti»
    moneyBack: true,         // «Se non ti soddisfa, ti rimborsiamo entro X giorni»
    cod: true,               // «Pagamento alla consegna»
    noPrepay: true,          // «Nessun pagamento anticipato»
    beforeAfter: true,       // модуль «до → после» с реальными материалами
    vitaminC: true,          // «La vitamina C contribuisce alla normale formazione del collagene…» (Reg. UE 432/2012, нужно ≥12 мг/сут)
    vsDrugs: false,           // «Farmaci antidolorifici… non agiscono sulla causa» (креатив «Cosa aiuta»)
    botanicals: true         // «funzionalità articolare» для куркумы, босвеллии, имбиря, когтя дьявола (перечень Минздрава)
  },

  form: {
    endpoint: "api/lead.php",
    offerEndpoint: "api/offer.php",
    reviewsEndpoint: "api/reviews.php?scope=public",
    timeoutMs: 40000,   // сервер пробует 2 адреса Webvork дважды
    // Обязательный чекбокс согласия на звонок и передачу данных партнёру.
    // Отключать только если юрист определил иное правовое основание.
    requireConsent: true,
    privacyVersion: "privacy-it-1.0"
  },

  analytics: {
    metaPixelId: "",          // пусто — пиксель не загружается
    // Пиксель грузится только после согласия в cookie-баннере.
    requireCookieConsent: true
  },

  // Демо-режим: заявки не отправляются, ответ сервера имитируется. Включается автоматически
  // при открытии файла с диска (file://) или вручную здесь.
  demo: false
};
