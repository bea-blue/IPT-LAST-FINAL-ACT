// Optional challenge: collapse and expand reply branches.
//
// The server renders every reply expanded and renders NO buttons, only an
// empty placeholder next to comments that have replies. This script creates
// the real <button type="button"> controls, so a visitor without JavaScript
// sees every comment and no broken controls.
//
// No comment text is read into or built by this script: labels are fixed
// strings plus a number, and the hidden attribute does the hiding.
'use strict';

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.toggle-slot[data-replies]').forEach((slot) => {
    const branch = document.getElementById(slot.dataset.replies);
    if (!branch) {
      return;
    }

    // All replies inside this branch, at any depth.
    const total = branch.querySelectorAll('.comment').length;

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'toggle-replies';
    button.setAttribute('aria-controls', branch.id);

    const setExpanded = (expanded) => {
      button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      button.textContent = `${expanded ? 'Hide' : 'Show'} replies (${total})`;
      branch.hidden = !expanded;
    };

    button.addEventListener('click', () => {
      setExpanded(button.getAttribute('aria-expanded') !== 'true');
    });

    setExpanded(true);
    slot.replaceWith(button);
  });
});
