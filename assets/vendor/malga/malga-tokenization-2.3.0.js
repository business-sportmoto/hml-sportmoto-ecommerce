var __defProp = Object.defineProperty;
var __defProps = Object.defineProperties;
var __getOwnPropDescs = Object.getOwnPropertyDescriptors;
var __getOwnPropSymbols = Object.getOwnPropertySymbols;
var __hasOwnProp = Object.prototype.hasOwnProperty;
var __propIsEnum = Object.prototype.propertyIsEnumerable;
var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
var __spreadValues = (a, b) => {
  for (var prop in b || (b = {}))
    if (__hasOwnProp.call(b, prop))
      __defNormalProp(a, prop, b[prop]);
  if (__getOwnPropSymbols)
    for (var prop of __getOwnPropSymbols(b)) {
      if (__propIsEnum.call(b, prop))
        __defNormalProp(a, prop, b[prop]);
    }
  return a;
};
var __spreadProps = (a, b) => __defProps(a, __getOwnPropDescs(b));
var __async = (__this, __arguments, generator) => {
  return new Promise((resolve, reject) => {
    var fulfilled = (value) => {
      try {
        step(generator.next(value));
      } catch (e) {
        reject(e);
      }
    };
    var rejected = (value) => {
      try {
        step(generator.throw(value));
      } catch (e) {
        reject(e);
      }
    };
    var step = (x) => x.done ? resolve(x.value) : Promise.resolve(x.value).then(fulfilled, rejected);
    step((generator = generator.apply(__this, __arguments)).next());
  });
};

// src/events/events.ts
var EventPostMessage = class {
  constructor(action, origin) {
    this.action = action;
    this.origin = origin;
  }
  send(eventType, data, origin) {
    this.action.postMessage({ eventType, data }, origin != null ? origin : this.origin);
  }
};
var EventListener = class {
  constructor(action) {
    this.action = action;
  }
  listener(eventType, eventHandler) {
    this.action.addEventListener(eventType, eventHandler);
  }
};
var Events = class {
  constructor() {
    this.events = {};
  }
  on(eventType, eventHandler) {
    var _a;
    if (!this.events[eventType]) {
      this.events[eventType] = [];
    }
    (_a = this.events[eventType]) == null ? void 0 : _a.push(eventHandler);
  }
  emit(eventType, data) {
    var _a;
    if (!this.events[eventType]) {
      return;
    }
    (_a = this.events[eventType]) == null ? void 0 : _a.forEach((eventHandler) => {
      eventHandler(data);
    });
  }
};

// src/events/validation.ts
function handGetValidationEventData(data, parentNode) {
  const isValid = data.valid;
  const isEmpty = data.empty;
  const isPotentiallyValid = data.potentialValid;
  if (isEmpty || isPotentiallyValid) {
    parentNode == null ? void 0 : parentNode.classList.remove("malga-hosted-field-valid" /* Valid */);
    parentNode == null ? void 0 : parentNode.classList.remove("malga-hosted-field-invalid" /* Invalid */);
    return;
  }
  parentNode == null ? void 0 : parentNode.classList.toggle("malga-hosted-field-valid" /* Valid */, isValid);
  parentNode == null ? void 0 : parentNode.classList.toggle("malga-hosted-field-invalid" /* Invalid */, !isValid);
  eventsEmitter.emit("validity" /* Validity */, {
    field: data.field,
    error: data.error,
    valid: data.valid,
    empty: data.empty,
    potentialValid: data.potentialValid,
    parentNode
  });
}

// src/utils/observer.ts
function waitingForElement(container, appendElement) {
  const element = document.querySelector(`#${container}`);
  if (element) {
    appendElement(element);
    return;
  }
  const observerElement = new MutationObserver((_, observer) => {
    const element2 = document.querySelector(`#${container}`);
    if (element2) {
      observer.disconnect();
      appendElement(element2);
    }
  });
  observerElement.observe(document.body, { childList: true, subtree: true });
}

// src/constants/url.ts
var URL_HOSTED_FIELD_DEV = "https://hosted-fields.dev.malga.io";
var URL_HOSTED_FIELD_SANDBOX = "https://hosted-fields-sandbox.malga.io";
var URL_HOSTED_FIELD_PROD = "https://hosted-fields.malga.io";

// src/utils/origin.ts
function gettingOriginEvent(debug, sandbox) {
  if (debug) {
    return URL_HOSTED_FIELD_DEV;
  }
  if (sandbox) {
    return URL_HOSTED_FIELD_SANDBOX;
  }
  return URL_HOSTED_FIELD_PROD;
}

// src/iframes/create.ts
function create(fieldConfig, debug, sandbox) {
  const origin = gettingOriginEvent(debug, sandbox);
  const iframe = document.createElement("iframe");
  iframe.setAttribute("name", fieldConfig.container);
  iframe.setAttribute("src", origin);
  iframe.setAttribute("width", "100%");
  iframe.setAttribute("height", "100%");
  iframe.setAttribute("frameborder", "0");
  waitingForElement(fieldConfig.container, (parentNode) => {
    parentNode == null ? void 0 : parentNode.appendChild(iframe);
    parentNode.classList.add("malga-hosted-field" /* Default */);
  });
  return iframe;
}

// src/iframes/loaded.ts
function validateConfig(config) {
  if (!config || typeof config !== "object") {
    console.error("Invalid configuration object");
    return false;
  }
  if (!config.fields || typeof config.fields !== "object") {
    console.error("Invalid fields configuration");
    return false;
  }
  return true;
}
function onLoadIframeField(iframe, fieldConfig, options) {
  var _a, _b;
  if (!iframe.contentWindow) {
    console.error("iframe.contentWindow is null, cannot send postMessage");
    return;
  }
  iframe.contentWindow.postMessage(
    {
      type: "setTypeField" /* SetTypeField */,
      field: fieldConfig.container,
      fieldConfig,
      styles: (_a = options.config) == null ? void 0 : _a.styles,
      preventAutofill: (_b = options.config) == null ? void 0 : _b.preventAutofill,
      debug: options == null ? void 0 : options.debug,
      sandbox: options == null ? void 0 : options.sandbox
    },
    "*"
  );
}
function loaded(options) {
  if (!validateConfig(options.config)) {
    return;
  }
  const fields = Object.keys(options.config.fields);
  fields.forEach((field) => {
    const fieldConfig = options.config.fields[field];
    const iframe = create(fieldConfig, options.debug, options.sandbox);
    if (!iframe) {
      console.error(`Error to access the iframe of ${field}`);
      return;
    }
    iframe.onload = () => onLoadIframeField(iframe, fieldConfig, options);
  });
}

// src/iframes/submit.ts
function submit(configurations) {
  var _a;
  const iframeCardNumber = document.querySelector(
    "iframe[name=card-number]"
  );
  if (!iframeCardNumber || !iframeCardNumber.contentWindow) {
    console.error(
      "iframeCardNumber is null or has no contentWindow, cannot send postMessage"
    );
    return;
  }
  const origin = gettingOriginEvent(
    configurations.options.debug,
    configurations.options.sandbox
  );
  const getSessionStorageCard = JSON.parse(
    sessionStorage.getItem("malga-card") || "{}"
  );
  const iframePostMessage = new EventPostMessage(
    iframeCardNumber.contentWindow,
    origin
  );
  iframePostMessage.send("submit" /* Submit */, {
    authorizationData: {
      clientId: configurations.clientId,
      apiKey: configurations.apiKey
    },
    sandbox: (_a = configurations.options) == null ? void 0 : _a.sandbox,
    debug: configurations.options.debug,
    card: getSessionStorageCard
  });
}

// src/iframes/listener.ts
function handleEventValidity(data, parentNode) {
  handGetValidationEventData(data, parentNode);
}
function handleEventCardTypeChanged(data, parentNode) {
  eventsEmitter.emit("cardTypeChanged" /* CardTypeChanged */, {
    field: data.field,
    parentNode,
    card: data.card
  });
}
function handleEventFocus(data, parentNode) {
  parentNode.classList.add("malga-hosted-field-focused" /* Focused */);
  eventsEmitter.emit("focus" /* Focus */, {
    field: data.field,
    parentNode
  });
}
function handleEventBlur(data, parentNode) {
  parentNode.classList.remove("malga-hosted-field-focused" /* Focused */);
  eventsEmitter.emit("blur" /* Blur */, {
    field: data.field,
    parentNode
  });
}
function handleEventUpdateCardValues(data) {
  const currentCardData = JSON.parse(
    sessionStorage.getItem("malga-card") || "{}"
  );
  const camelCaseField = data.field.replace(
    /-([a-z])/g,
    (g) => g[1].toUpperCase()
  );
  const updatedCardData = __spreadProps(__spreadValues({}, currentCardData), {
    [camelCaseField]: data.value
  });
  sessionStorage.setItem("malga-card", JSON.stringify(updatedCardData));
}
var eventHandlers = {
  ["validity" /* Validity */]: handleEventValidity,
  ["cardTypeChanged" /* CardTypeChanged */]: handleEventCardTypeChanged,
  ["focus" /* Focus */]: handleEventFocus,
  ["blur" /* Blur */]: handleEventBlur,
  ["updateCardValues" /* UpdateCardValues */]: handleEventUpdateCardValues
};
function listener(debug, sandbox) {
  const windowMessage = new EventListener(window.parent);
  windowMessage.listener("message", (event) => {
    const origin = gettingOriginEvent(debug, sandbox);
    if (event.origin !== origin) {
      return `Unauthorized origin: ${event.origin}`;
    }
    try {
      const { eventType, data } = event.data;
      const parentNode = document.querySelector(`#${data == null ? void 0 : data.field}`);
      if (!parentNode)
        return;
      const handler = eventHandlers[eventType];
      if (handler) {
        handler(data, parentNode);
      } else {
        console.warn(`Unhandled event type: ${eventType}`);
      }
    } catch (error) {
      console.error("Error handling message event:", error);
    }
  });
}

// src/tokenize/tokenize.ts
var Tokenize = class {
  constructor(configurations) {
    this.configurations = configurations;
  }
  isValidOrigin(origin) {
    var _a, _b;
    const allowedOrigin = gettingOriginEvent(
      (_a = this.configurations.options) == null ? void 0 : _a.debug,
      (_b = this.configurations.options) == null ? void 0 : _b.sandbox
    );
    return origin === allowedOrigin;
  }
  handle() {
    return __async(this, null, function* () {
      if (!this.configurations) {
        throw new Error("Configurations are required");
      }
      submit(this.configurations);
      const windowData = new EventListener(window);
      return new Promise((resolve, reject) => {
        const messageHandler = (event) => {
          if (!this.isValidOrigin(event.origin)) {
            console.error(
              `Unauthorized origin: ${event.origin}, origin should be ${gettingOriginEvent()}`
            );
            return;
          }
          if (event.data.eventType === "tokenize" /* Tokenize */) {
            try {
              resolve(event.data.data);
            } catch (error) {
              console.error("Error processing tokenize event:", error);
              reject(error);
            } finally {
              window.removeEventListener("message", messageHandler);
            }
          }
        };
        windowData.listener("message", messageHandler);
      });
    });
  }
};

// src/tokenization.ts
var eventsEmitter = new Events();
var MalgaTokenization = class {
  constructor(configurations) {
    if (!configurations.apiKey || !configurations.clientId) {
      console.error(
        'Missing API key. Pass it to the constructor `new MalgaTokenization({ apiKey: "YOUR_API_KEY", clientId: "YOUR_CLIENT_ID" })`'
      );
    }
    sessionStorage.removeItem("malga-card");
    this.configurations = configurations;
    loaded(configurations.options);
    listener(configurations.options.debug, configurations.options.sandbox);
  }
  tokenize() {
    return __async(this, null, function* () {
      const tokenize = new Tokenize(this.configurations);
      return tokenize.handle();
    });
  }
  /**
   * Configures the event provider and registers an event handler for the specified event type.
   *
   * This method allows you to react the specifics events emitted by the MalgaTokenization component.
   *
   * @param eventType - The type of event to be watched. Possible values are:
   * - 'validity': Triggered when the validity of the field data is changed (valid/invalid).
   * - 'cardTypeChanged': Triggered when the card type is detected or changed.
   * - 'focus': Triggered when a input field receives focus.
   * - 'blur': Triggered when a input field loses focus.
   * @param eventHandler - The event handler function.
   * @returns {void}
   */
  on(eventType, eventHandler) {
    return eventsEmitter.on(eventType, eventHandler);
  }
  // public on(eventType: EventTypeReturn, eventHandler: (event: any) => void) {
  //   return eventsEmitter.on(eventType, eventHandler)
  // }
};
export {
  MalgaTokenization
};
