// seerrAvatar(): the Seerr pages' client-side twin of SeerrAvatarExtension
// (live check on :beta, 2026-11-07). Plex users' absolute plex.tv avatars
// were glued onto the Seerr URL (`http://seerr:5055https://plex.tv/…`); a
// Seerr path resolves, anything absolute is '' (the placeholder).
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const PAGES = ['jellyseerr/index.html.twig', 'jellyseerr/users.html.twig'];

function load(template, seerrUrl) {
  return runSlice(template, 'function seerrAvatar(', 'seerrAvatar', { SEERR_URL: seerrUrl }).seerrAvatar;
}

for (const template of PAGES) {
  describe(`seerrAvatar (${template})`, () => {
    it('resolves a Seerr path against the Seerr URL with one slash', () => {
      assert.equal(load(template, 'http://seerr:5055')('/avatarproxy/abc'), 'http://seerr:5055/avatarproxy/abc');
      assert.equal(load(template, 'http://seerr:5055/')('avatarproxy/abc'), 'http://seerr:5055/avatarproxy/abc');
    });

    it('drops an absolute avatar instead of prefixing it', () => {
      const seerrAvatar = load(template, 'http://seerr:5055');
      for (const abs of ['https://plex.tv/users/1/avatar?c=2', 'http://gravatar.com/x', '//plex.tv/x', 'data:image/png;base64,AA', 'HTTPS://PLEX.TV/x']) {
        assert.equal(seerrAvatar(abs), '', abs);
      }
    });

    it('is empty with nothing to resolve or no Seerr URL', () => {
      const seerrAvatar = load(template, 'http://seerr:5055');
      assert.equal(seerrAvatar(null), '');
      assert.equal(seerrAvatar(undefined), '');
      assert.equal(seerrAvatar(''), '');
      assert.equal(load(template, '')('/avatarproxy/abc'), '');
    });
  });
}
