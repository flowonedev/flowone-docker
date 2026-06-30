<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\LabelService;
use Webmail\Services\FilterService;
use Webmail\Services\RedisCacheService;

class LabelController extends BaseController
{
    private ?LabelService $labelService = null;
    private ?RedisCacheService $redisCache = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    private function getRedisCacheService(): RedisCacheService
    {
        if (!$this->redisCache) {
            $this->redisCache = new RedisCacheService($this->config);
        }
        return $this->redisCache;
    }
    
    /**
     * Get LabelService (lazy init)
     */
    private function getLabelService(): LabelService
    {
        if (!$this->labelService) {
            $this->labelService = new LabelService($this->config);
        }
        return $this->labelService;
    }
    
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        return Response::success([
            'labels' => $this->getLabelService()->getLabels($activeEmail),
            'colors' => $this->getLabelService()->getColors(),
        ]);
    }
    
    public function create(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $name = $request->input('name');
        if (!$name) {
            return Response::error('Label name is required');
        }
        
        $label = $this->getLabelService()->createLabel(
            $activeEmail,
            $name,
            $request->input('color', '#3b82f6')
        );
        
        if (!$label) {
            return Response::error('Label already exists');
        }
        
        BootstrapController::invalidateCache($this->config, $activeEmail);
        return Response::success(['label' => $label]);
    }
    
    public function update(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $labelId = (int)$request->getParam('id');
        
        $name = $request->input('name');
        $color = $request->input('color');
        
        if (!$name || !$color) {
            return Response::error('Name and color are required');
        }
        
        // Get old label name before update (for filter reference updates)
        $labels = $this->getLabelService()->getLabels($activeEmail);
        $oldLabel = null;
        foreach ($labels as $label) {
            if ($label['id'] === $labelId) {
                $oldLabel = $label;
                break;
            }
        }
        
        if (!$this->getLabelService()->updateLabel($activeEmail, $labelId, $name, $color)) {
            return Response::error('Label not found');
        }
        
        // Update filter references if label name changed
        $updatedFilters = 0;
        if ($oldLabel && $oldLabel['name'] !== $name) {
            try {
                $filterService = new FilterService($this->config);
                $updatedFilters = $filterService->updateLabelReferences($activeEmail, $oldLabel['name'], $name);
                if ($updatedFilters > 0) {
                    error_log("Updated $updatedFilters filter(s) after renaming label '{$oldLabel['name']}' to '$name'");
                }
            } catch (\Exception $e) {
                error_log("Warning: Failed to update filters after label rename: " . $e->getMessage());
            }
        }
        
        BootstrapController::invalidateCache($this->config, $activeEmail);
        return Response::success([
            'message' => 'Label updated',
            'filters_updated' => $updatedFilters
        ]);
    }
    
    public function delete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        if (!$this->getLabelService()->deleteLabel($activeEmail, (int)$request->getParam('id'))) {
            return Response::error('Label not found');
        }
        
        BootstrapController::invalidateCache($this->config, $activeEmail);
        return Response::success(['message' => 'Label deleted']);
    }
    
    public function getMessageLabels(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $messageId = $request->input('message_id') ?? $request->getQuery('message_id');
        if (!$messageId) {
            return Response::error('Message ID required');
        }
        
        return Response::success([
            'labels' => $this->getLabelService()->getMessageLabels($activeEmail, $messageId)
        ]);
    }
    
    public function addToMessage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $messageId = $request->input('message_id');
        $labelId = (int)$request->input('label_id');
        
        if (!$messageId || !$labelId) {
            return Response::error('Message ID and Label ID required');
        }
        
        $this->getLabelService()->addLabelToMessage($activeEmail, $messageId, $labelId);
        
        $labels = $this->getLabelService()->getLabels($activeEmail);
        $labelData = null;
        foreach ($labels as $l) {
            if ($l['id'] === $labelId) {
                $labelData = $l;
                break;
            }
        }
        $this->getRedisCacheService()->publishLabelsChanged($activeEmail, $messageId, $labelId, 'add', $labelData);
        
        return Response::success(['message' => 'Label added']);
    }
    
    public function removeFromMessage(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $messageId = $request->input('message_id');
        $labelId = $request->input('label_id');
        
        error_log("Remove label request - message_id: " . var_export($messageId, true) . ", label_id: " . var_export($labelId, true) . ", user: " . $activeEmail);
        
        if (!$messageId || !$labelId) {
            error_log("Remove label failed - missing params. message_id: " . ($messageId ? 'set' : 'empty') . ", label_id: " . ($labelId ? 'set' : 'empty'));
            return Response::error('Message ID and Label ID required');
        }
        
        $result = $this->getLabelService()->removeLabelFromMessage($activeEmail, $messageId, (int)$labelId);
        error_log("Remove label result: " . ($result ? 'success' : 'failed'));
        
        $this->getRedisCacheService()->publishLabelsChanged($activeEmail, $messageId, (int)$labelId, 'remove');
        
        return Response::success(['message' => 'Label removed']);
    }
}
