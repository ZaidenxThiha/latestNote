const API = {
  async saveNote(noteId, data) {
    const url = noteId
      ? `notes_backend.php?save=1&id=${noteId}`
      : "notes_backend.php?save=1&id=0";
    console.log(`Saving note to URL: ${url}`, data);
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
    if (!response.ok) {
      console.error(
        `Save note failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    const result = await response.json();
    console.log(`Save note response:`, result);
    return result;
  },

  async loadNote(id) {
    console.log(`Loading note ID: ${id}`);
    const response = await fetch(`notes_backend.php?action=edit&id=${id}`);
    if (!response.ok) {
      console.error(
        `Load note failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      const errorData = text ? JSON.parse(text) : { error: "Unknown error" };
      throw new Error(`HTTP error! Status: ${response.status}`, {
        cause: { is_locked: errorData.is_locked || false },
      });
    }
    try {
      const note = await response.json();
      console.log(`Load note response:`, note);
      return note;
    } catch (e) {
      console.error(`JSON parsing error: ${e.message}`);
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`Unexpected token in response: ${e.message}`);
    }
  },

  async loadNotes(search = "", label = "") {
    console.log(`Loading notes with search: ${search}, label: ${label}`);
    const url = `notes_backend.php?action=list&search=${encodeURIComponent(
      search
    )}&label=${encodeURIComponent(label)}`;
    const response = await fetch(url, {
      headers: { Accept: "text/html" },
    });
    if (!response.ok) {
      console.error(
        `Load notes failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    const html = await response.text();
    if (!html.trim()) {
      console.warn("No notes returned from server");
    }
    return html;
  },

  async uploadImage(formData) {
    console.log(`Uploading image`);
    const response = await fetch("notes_backend.php", {
      method: "POST",
      body: formData,
    });
    if (!response.ok) {
      console.error(
        `Upload image failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    const result = await response.json();
    return result;
  },

  async addLabel(labelName) {
    console.log(`Adding label: ${labelName}`);
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `add_label=1&label_name=${encodeURIComponent(labelName)}`,
    });
    if (!response.ok) {
      console.error(
        `Add label failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async renameLabel(oldName, newName) {
    console.log(`Renaming label from ${oldName} to ${newName}`);
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `rename_label=1&old_name=${encodeURIComponent(
        oldName
      )}&new_name=${encodeURIComponent(newName)}`,
    });
    if (!response.ok) {
      console.error(
        `Rename label failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async deleteLabel(labelName) {
    console.log(`Deleting label: ${labelName}`);
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `delete_label=1&label_name=${encodeURIComponent(labelName)}`,
    });
    if (!response.ok) {
      console.error(
        `Delete label failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async setPassword(noteId, password) {
    console.log(`Setting password for note ID: ${noteId}`);
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `set_password=1&note_id=${encodeURIComponent(
        noteId
      )}&password=${encodeURIComponent(password)}`,
    });
    if (!response.ok) {
      console.error(
        `Set password failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async removePassword(noteId) {
    console.log(`Removing password for note ID: ${noteId}`);
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `remove_password=1&note_id=${encodeURIComponent(noteId)}`,
    });
    if (!response.ok) {
      console.error(
        `Remove password failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async verifyPassword(noteId, password) {
    console.log(`Verifying password for note ID: ${noteId}`);
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `verify_password=1&note_id=${encodeURIComponent(
        noteId
      )}&password=${encodeURIComponent(password)}`,
    });
    if (!response.ok) {
      console.error(
        `Verify password failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async shareNote(noteId, emails, permission) {
    console.log(
      `Sharing note ID: ${noteId} with emails: ${emails}, permission: ${permission}`
    );
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `share_note=1&note_id=${encodeURIComponent(
        noteId
      )}&emails=${encodeURIComponent(emails)}&permission=${encodeURIComponent(
        permission
      )}`,
    });
    if (!response.ok) {
      console.error(
        `Share note failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async updateShare(noteId, recipientUserId, permission) {
    console.log(
      `Updating share for note ID: ${noteId}, user ID: ${recipientUserId}, permission: ${permission}`
    );
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `update_share=1&note_id=${encodeURIComponent(
        noteId
      )}&recipient_user_id=${encodeURIComponent(
        recipientUserId
      )}&permission=${encodeURIComponent(permission)}`,
    });
    if (!response.ok) {
      console.error(
        `Update share failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async revokeShare(noteId, recipientUserId) {
    console.log(
      `Revoking share for note ID: ${noteId}, user ID: ${recipientUserId}`
    );
    const response = await fetch("notes_backend.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: `revoke_share=1&note_id=${encodeURIComponent(
        noteId
      )}&recipient_user_id=${encodeURIComponent(recipientUserId)}`,
    });
    if (!response.ok) {
      console.error(
        `Revoke share failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async deleteNote(id) {
    console.log(`Deleting note ID: ${id}`);
    const response = await fetch(
      `notes_backend.php?delete=1&id=${encodeURIComponent(id)}`
    );
    if (!response.ok) {
      console.error(
        `Delete note failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },

  async pinNote(id, pin) {
    console.log(`Pinning note ID: ${id}, pin: ${pin}`);
    const response = await fetch(
      `notes_backend.php?pin=${encodeURIComponent(pin)}&id=${encodeURIComponent(
        id
      )}`
    );
    if (!response.ok) {
      console.error(
        `Pin note failed: ${response.status} ${response.statusText}`
      );
      const text = await response.text();
      console.error(`Response content: ${text.slice(0, 100)}...`);
      throw new Error(`HTTP error! Status: ${response.status}`);
    }
    return response.json();
  },
};
