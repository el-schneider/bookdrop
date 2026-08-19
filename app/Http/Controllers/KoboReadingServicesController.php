<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kobo resolves `reading_services_host` as an ORIGIN, not a base path: the tokenised prefix we
 * advertise is dropped and annotation calls arrive at the site root as /api/v3/content/...
 *
 * These root-level endpoints therefore exist to receive them. They are deliberately inert for now
 * — they never hand annotations out and never claim to have stored any — because the request shape
 * and the credentials the device sends still have to be observed before this can authenticate
 * properly. Enable BOOKDROP_RECORD_KOBO_TRAFFIC to capture one real exchange.
 */
class KoboReadingServicesController extends Controller
{
    public function checkForChanges(Request $request): JsonResponse
    {
        $this->observe($request, 'checkforchanges');

        // Reporting nothing as changed is the conservative answer: it cannot trigger a device-side
        // deletion, and the device still uploads new annotations on its own schedule.
        return response()->json([]);
    }

    public function index(Request $request, string $contentId): JsonResponse
    {
        $this->observe($request, 'annotations.get', $contentId);

        // Empty list with NO ETag. This is the one shape that is safe when the server holds
        // nothing: an ETag here would tell the device its local copy is superseded and it would
        // delete the annotations.
        return response()->json(['annotations' => [], 'nextPageOffsetToken' => null]);
    }

    public function update(Request $request, string $contentId): JsonResponse
    {
        $this->observe($request, 'annotations.patch', $contentId, [
            'annotation_count' => count((array) $request->input('updatedAnnotations', [])),
        ]);

        // Acknowledged without an ETag: the device keeps its copy, and the payload is preserved in
        // the traffic recording rather than in a schema guessed at from an unverified request.
        return response()->json(['result' => 'ok']);
    }

    /**
     * Anything else the device asks of reading services. Logged so the unknown paths stop being
     * invisible; 404 on non-annotation paths is tolerated by the device.
     */
    public function fallback(Request $request, string $path): JsonResponse
    {
        $this->observe($request, 'unhandled');

        return response()->json([], 404);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function observe(Request $request, string $event, ?string $contentId = null, array $context = []): void
    {
        logger()->warning('Kobo reading services '.$event, $context + [
            'content_id' => $contentId,
            'path' => $request->path(),
            // Header names only: their values carry device credentials.
            'header_names' => implode(',', array_keys($request->headers->all())),
            'has_authorization' => $request->hasHeader('authorization'),
            'has_userkey' => $request->hasHeader('x-kobo-userkey'),
        ]);
    }
}
