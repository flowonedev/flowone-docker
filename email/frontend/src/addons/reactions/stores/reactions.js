import { defineStore } from "pinia";
import { ref } from "vue";
import api from "@/services/api";
import { useThemeStore } from "@/stores/theme";
import { useAddons } from "@/composables/useAddons";

export const useReactionsStore = defineStore("reactions", () => {
  // State - reactions indexed by message_id
  const reactions = ref({});
  const availableEmojis = ref([]);
  const loading = ref(false);

  // Get theme store for accent color
  const getAccentColor = () => {
    try {
      const theme = useThemeStore();
      return theme.accentColor || "blue";
    } catch {
      return "blue";
    }
  };

  // Load available emojis on init
  async function loadEmojis() {
    const { reactionsEnabled } = useAddons();
    if (!reactionsEnabled.value) return;
    if (availableEmojis.value.length > 0) return;

    try {
      const response = await api.get("/reactions/emojis");
      if (response.data.success) {
        availableEmojis.value = response.data.data.emojis;
      }
    } catch (e) {
      console.error("Failed to load emojis:", e);
      // Fallback emojis
      availableEmojis.value = [
        { key: "thumbsup", emoji: "👍" },
        { key: "heart", emoji: "❤️" },
        { key: "party", emoji: "🎉" },
        { key: "laugh", emoji: "😂" },
        { key: "surprised", emoji: "😲" },
        { key: "worried", emoji: "😟" },
      ];
    }
  }

  // Get reactions for a single message (cached)
  async function fetchReactions(messageId, forceRefresh = false) {
    const { reactionsEnabled } = useAddons();
    if (!reactionsEnabled.value) return [];
    if (!messageId) return [];

    // Return cached if available
    if (!forceRefresh && messageId in reactions.value) {
      return reactions.value[messageId];
    }

    try {
      const response = await api.get("/reactions/message", {
        params: { message_id: messageId },
      });
      if (response.data.success) {
        reactions.value[messageId] = response.data.data.reactions;
        return response.data.data.reactions;
      }
    } catch (e) {
      console.error("Failed to fetch reactions:", e);
    }
    return [];
  }

  // Get reactions for multiple messages (batch)
  async function fetchReactionsBatch(messageIds) {
    const { reactionsEnabled } = useAddons();
    if (!reactionsEnabled.value) return {};
    if (!messageIds || messageIds.length === 0) return {};

    // Filter out empty/null ids
    const validIds = messageIds.filter((id) => id);
    if (validIds.length === 0) return {};

    try {
      const response = await api.post("/reactions/batch", {
        message_ids: validIds,
      });
      if (response.data.success) {
        const newReactions = response.data.data.reactions;
        // Merge into state
        Object.assign(reactions.value, newReactions);
        return newReactions;
      }
    } catch (e) {
      console.error("Failed to fetch reactions batch:", e);
    }
    return {};
  }

  // Add or toggle a reaction
  async function addReaction(
    messageId,
    emoji,
    participants,
    subject,
    snippet = null,
    sendNotification = true
  ) {
    const { reactionsEnabled } = useAddons();
    if (!reactionsEnabled.value) return null;
    if (!messageId || !emoji) return null;

    loading.value = true;
    try {
      const response = await api.post("/reactions", {
        message_id: messageId,
        emoji,
        participants,
        subject,
        snippet,
        send_notification: sendNotification,
        accent_color: getAccentColor(), // Pass user's accent color for email styling
      });

      if (response.data.success) {
        const data = response.data.data;

        // Update local state
        if (data.summary) {
          reactions.value[messageId] = data.summary;
        } else if (data.action === "removed") {
          // Refetch to get updated state
          await fetchReactions(messageId);
        }

        return data;
      }
    } catch (e) {
      console.error("Failed to add reaction:", e);
    } finally {
      loading.value = false;
    }
    return null;
  }

  // Remove a reaction
  async function removeReaction(messageId, emoji) {
    if (!messageId || !emoji) return false;

    try {
      const response = await api.delete("/reactions", {
        data: { message_id: messageId, emoji },
      });

      if (response.data.success) {
        // Update local state
        if (response.data.data.summary) {
          reactions.value[messageId] = response.data.data.summary;
        }
        return true;
      }
    } catch (e) {
      console.error("Failed to remove reaction:", e);
    }
    return false;
  }

  // Get reactions for a message from local cache
  function getReactions(messageId) {
    return reactions.value[messageId] || [];
  }

  // Check if user has reacted with specific emoji
  function hasUserReacted(messageId, emoji) {
    const msgReactions = reactions.value[messageId] || [];
    const reaction = msgReactions.find((r) => r.emoji === emoji);
    return reaction?.user_reacted || false;
  }

  // Clear reactions cache
  function clearCache() {
    reactions.value = {};
  }

  // Don't auto-initialize - let components load when needed

  return {
    reactions,
    availableEmojis,
    loading,
    loadEmojis,
    fetchReactions,
    fetchReactionsBatch,
    addReaction,
    removeReaction,
    getReactions,
    hasUserReacted,
    clearCache,
  };
});


