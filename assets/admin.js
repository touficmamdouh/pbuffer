(() => {
  const cfg = window.lightweb2Admin;

  if (!cfg) {
    return;
  }

  const byId = (id) => document.getElementById(id);
  const tokenInput = byId('lightweb2-token');
  const channelsBox = byId('lightweb2-channels');
  const statusBox = byId('lightweb2-connection-status');
  const ajaxStatus = byId('lightweb2-ajax-status');
  const template = byId('lightweb2-template');
  const preview = byId('lightweb2-preview');
  const selected = new Set(cfg.selectedChannels || []);

  const setStatus = (message, connected = false) => {
    if (statusBox) {
      statusBox.textContent = message;
      statusBox.classList.toggle('is-connected', connected);
    }
  };

  const setAjaxStatus = (message, isError = false) => {
    if (ajaxStatus) {
      ajaxStatus.textContent = message;
      ajaxStatus.classList.toggle('is-error', isError);
    }
  };

  const request = async (action, extra = {}) => {
    const body = new FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce);
    body.append('token', tokenInput ? tokenInput.value : '');

    Object.entries(extra).forEach(([key, value]) => body.append(key, value));

    const response = await fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });
    const json = await response.json();

    if (!json.success) {
      throw new Error(json?.data?.message || cfg.strings.error);
    }

    return json.data;
  };

  const renderChannels = (organizations) => {
    if (!channelsBox) {
      return;
    }

    channelsBox.innerHTML = '';

    if (!organizations.length) {
      const empty = document.createElement('p');
      empty.className = 'description';
      empty.textContent = cfg.strings.noChannels;
      channelsBox.appendChild(empty);
      return;
    }

    organizations.forEach((organization) => {
      const group = document.createElement('fieldset');
      group.className = 'lightweb2-channel-group';

      const legend = document.createElement('legend');
      legend.textContent = organization.name || organization.id;
      group.appendChild(legend);

      (organization.channels || []).forEach((channel) => {
        const label = document.createElement('label');
        label.className = 'lightweb2-channel';

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.name = 'lightweb2_settings[channels][]';
        checkbox.value = channel.id;
        checkbox.checked = selected.has(channel.id);
        label.appendChild(checkbox);

        const name = document.createElement('span');
        name.textContent = channel.name || channel.id;
        label.appendChild(name);

        const service = document.createElement('small');
        service.textContent = channel.service || '';
        label.appendChild(service);

        group.appendChild(label);
      });

      channelsBox.appendChild(group);
    });
  };

  byId('lightweb2-test')?.addEventListener('click', async () => {
    setAjaxStatus(cfg.strings.testing);

    try {
      const data = await request('lightweb2_test_connection');
      const message = cfg.strings.connected.replace('%s', data.account || 'Buffer');
      setStatus(message, true);
      setAjaxStatus(message);
    } catch (error) {
      setStatus(cfg.strings.error, false);
      setAjaxStatus(error.message, true);
    }
  });

  byId('lightweb2-load-channels')?.addEventListener('click', async () => {
    setAjaxStatus(cfg.strings.loading);

    try {
      const data = await request('lightweb2_fetch_channels');
      renderChannels(data.organizations || []);
      setAjaxStatus('');
    } catch (error) {
      setAjaxStatus(error.message, true);
    }
  });

  byId('lightweb2-disconnect')?.addEventListener('click', async () => {
    if (!window.confirm(cfg.strings.confirmDisconnect)) {
      return;
    }

    try {
      await request('lightweb2_disconnect');
      window.location.reload();
    } catch (error) {
      setAjaxStatus(error.message, true);
    }
  });

  const updatePreview = () => {
    if (!template || !preview) {
      return;
    }

    const sample = {
      '{title}': 'A clearer way to publish',
      '{url}': 'https://example.com/article',
      '{excerpt}': 'A short summary of the article.',
      '{author}': 'LightWeb2',
      '{category}': 'Product',
      '{tags}': '#WordPress #Buffer',
    };

    preview.textContent = template.value.replace(
      /{[a-z]+}/g,
      (match) => sample[match] || match
    );
  };

  template?.addEventListener('input', updatePreview);
  updatePreview();

  if (channelsBox) {
    channelsBox.addEventListener('change', (event) => {
      if (event.target instanceof HTMLInputElement && event.target.type === 'checkbox') {
        if (event.target.checked) {
          selected.add(event.target.value);
        } else {
          selected.delete(event.target.value);
        }
      }
    });
  }
})();
