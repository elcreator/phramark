// The workload appends the word EDITED to a title and a body. Repeated rounds
// must do identical work, so an earlier marker is stripped before appending
// instead of letting the suffix grow.

export const MARKER = 'EDITED';

const TRAILING_MARKERS = /(\s+EDITED)+\s*$/;
const TRAILING_MARKERS_IN_PARAGRAPH = /(\s+EDITED)+\s*(<\/p>\s*)$/i;

export function stripMarker(value) {
  return value.replace(TRAILING_MARKERS_IN_PARAGRAPH, '$2').replace(TRAILING_MARKERS, '');
}

export function editedTitle(title) {
  return `${stripMarker(title).trimEnd()} ${MARKER}`;
}

export function editedContent(content) {
  const trimmed = stripMarker(content).trimEnd();
  const closing = /<\/p>\s*$/i.exec(trimmed);
  if (closing) {
    return `${trimmed.slice(0, closing.index).trimEnd()} ${MARKER}</p>`;
  }
  return `${trimmed} ${MARKER}`;
}

export function createdTitle(round, index) {
  return `Playwright page r${round}-${index}`;
}

export function createdContent(round, index) {
  return `<p>Created by the Phramark admin workload, round ${round}, page ${index}.</p>`;
}
