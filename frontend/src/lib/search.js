const plain = (text) =>
  String(text ?? "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .toLowerCase();

export function searchWords(query) {
  return plain(query).split(/\s+/).filter(Boolean);
}

export function matchesWords(text, words) {
  if (!words.length) return true;
  const haystack = plain(text);
  const digits = haystack.replace(/\D/g, "");
  return words.every((word) => {
    if (haystack.includes(word)) return true;
    const wordDigits = word.replace(/\D/g, "");
    return wordDigits.length >= 4 && wordDigits.length === word.replace(/-/g, "").length && digits.includes(wordDigits);
  });
}
