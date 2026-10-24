import api from "../services/api";

/*
  Download a file made by the server (PDF, Word, Excel) and save it with the
  name the server gives. When the server answers with an error, the message
  inside the answer is read so staff see the real reason.
*/
export async function downloadFile(url, body, fallbackName) {
  let response;
  try {
    response = await api.post(url, body, { responseType: "blob" });
  } catch (err) {
    const data = err?.response?.data;
    if (data instanceof Blob) {
      try {
        const parsed = JSON.parse(await data.text());
        if (parsed?.message) err.response.data = parsed;
      } catch {
        // not JSON: keep the original error
      }
    }
    throw err;
  }

  const disposition = response.headers?.["content-disposition"] || "";
  const name = disposition.match(/filename="?([^";]+)"?/)?.[1] || fallbackName;
  const link = document.createElement("a");
  link.href = URL.createObjectURL(response.data);
  link.download = name;
  document.body.appendChild(link); // some browsers only download links that are on the page
  link.click();
  link.remove();
  setTimeout(() => URL.revokeObjectURL(link.href), 10000); // give the browser time to save it
  return name;
}
