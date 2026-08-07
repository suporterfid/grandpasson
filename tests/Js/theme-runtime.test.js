const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

class Element {
  constructor(tagName) {
    this.tagName = tagName;
    this.children = [];
    this.attributes = {};
    this.listeners = {};
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  getAttribute(name) {
    return this.attributes[name];
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  replaceChildren(...children) {
    this.children = children;
  }

  addEventListener(name, listener) {
    this.listeners[name] = listener;
  }
}

function loadThemeRuntime(language) {
  const mediaQuery = {
    matches: false,
    addEventListener(name, listener) {
      this.listeners ??= {};
      this.listeners[name] = listener;
    },
  };
  const root = new Element('html');
  root.lang = language;
  const themeColor = new Element('meta');
  const mount = new Element('div');
  let domReady;
  const document = {
    documentElement: root,
    readyState: 'loading',
    querySelector(selector) {
      return selector === 'meta[name="theme-color"]' ? themeColor : null;
    },
    querySelectorAll(selector) {
      return selector === '[data-theme-switcher]' ? [mount] : [];
    },
    createElement(tagName) {
      return new Element(tagName);
    },
    createTextNode(value) {
      return { value };
    },
    addEventListener(name, listener) {
      if (name === 'DOMContentLoaded') {
        domReady = listener;
      }
    },
  };
  const context = {
    document,
    window: {
      localStorage: {
        getItem() {
          return null;
        },
        setItem() {},
      },
      matchMedia() {
        return mediaQuery;
      },
    },
  };
  const themeRuntime = fs.readFileSync(
    path.join(__dirname, '../../public_html/assets/theme.js'),
    'utf8'
  );

  vm.runInNewContext(themeRuntime, context);
  domReady();

  return mount;
}

test('renders Portuguese switcher legend and options for lowercase pt-br', () => {
  const mount = loadThemeRuntime('pt-br');
  const group = mount.children[0];
  const options = group.children.map((option) => option.children[1].value);

  assert.equal(group.getAttribute('aria-label'), 'Tema');
  assert.deepEqual(options, ['Claro', 'Escuro', 'Sistema']);
});
