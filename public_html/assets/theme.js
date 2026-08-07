(function () {
  'use strict';

  var storageKey = 'grandpasson.theme';
  var preferences = ['light', 'dark', 'system'];
  var root = document.documentElement;
  var mediaQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
  var messages = {
    en: {
      theme: 'Theme',
      light: 'Light',
      dark: 'Dark',
      system: 'System'
    },
    'pt-br': {
      theme: 'Tema',
      light: 'Claro',
      dark: 'Escuro',
      system: 'Sistema'
    }
  };

  function readPreference() {
    try {
      var value = window.localStorage.getItem(storageKey);
      return preferences.indexOf(value) !== -1 ? value : 'system';
    } catch (error) {
      return 'system';
    }
  }

  function resolvedTheme(preference) {
    if (preference === 'light' || preference === 'dark') {
      return preference;
    }

    return mediaQuery && mediaQuery.matches ? 'dark' : 'light';
  }

  function applyTheme(preference) {
    var theme = resolvedTheme(preference);
    var themeColor = document.querySelector('meta[name="theme-color"]');

    root.setAttribute('data-theme', theme);

    if (themeColor) {
      themeColor.setAttribute('content', theme === 'dark' ? '#191919' : '#FFFFFF');
    }
  }

  function labelsForDocument() {
    var language = document.documentElement.lang.toLowerCase();

    return messages[language] || messages.en;
  }

  function enhanceSwitcher() {
    var labels = labelsForDocument();
    var mounts = document.querySelectorAll('[data-theme-switcher]');

    mounts.forEach(function (mount, mountIndex) {
      var group = document.createElement('div');
      group.className = 'theme-switcher__group';
      group.setAttribute('role', 'radiogroup');
      group.setAttribute('aria-label', labels.theme);

      preferences.forEach(function (value) {
        var id = 'theme-switcher-' + mountIndex + '-' + value;
        var option = document.createElement('label');
        var input = document.createElement('input');

        option.className = 'theme-switcher__option';
        option.setAttribute('for', id);
        input.className = 'theme-switcher__input';
        input.id = id;
        input.name = 'grandpasson-theme-' + mountIndex;
        input.type = 'radio';
        input.value = value;
        input.checked = preference === value;
        input.addEventListener('change', function () {
          if (!input.checked) {
            return;
          }

          try {
            window.localStorage.setItem(storageKey, value);
          } catch (error) {
            // The in-memory preference still applies when storage is unavailable.
          }

          preference = value;
          applyTheme(preference);
        });

        option.appendChild(input);
        option.appendChild(document.createTextNode(labels[value]));
        group.appendChild(option);
      });

      mount.replaceChildren(group);
    });
  }

  var preference = readPreference();
  applyTheme(preference);

  if (mediaQuery) {
    var updateForSystemTheme = function () {
      if (preference === 'system') {
        applyTheme(preference);
      }
    };

    if (typeof mediaQuery.addEventListener === 'function') {
      mediaQuery.addEventListener('change', updateForSystemTheme);
    } else if (typeof mediaQuery.addListener === 'function') {
      mediaQuery.addListener(updateForSystemTheme);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      enhanceSwitcher();
    });
  } else {
    enhanceSwitcher();
  }
}());
