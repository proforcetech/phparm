import AsyncStorage from '@react-native-async-storage/async-storage'
import * as Notifications from 'expo-notifications'
import { Platform } from 'react-native'

import { driverPushTokenService } from './driverPushToken.service'

const storedTokenKey = 'driver_push_token'

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: false,
  }),
})

export async function registerForPushNotificationsAsync(): Promise<string | null> {
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'default',
      importance: Notifications.AndroidImportance.MAX,
    })
  }

  const { status: existingStatus } = await Notifications.getPermissionsAsync()
  let finalStatus = existingStatus

  if (existingStatus !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync()
    finalStatus = status
  }

  if (finalStatus !== 'granted') {
    return null
  }

  const tokenResponse = await Notifications.getDevicePushTokenAsync()
  return tokenResponse.data
}

export async function syncDriverPushToken(): Promise<void> {
  const token = await registerForPushNotificationsAsync()
  if (!token) {
    return
  }

  const cachedToken = await AsyncStorage.getItem(storedTokenKey)
  if (cachedToken === token) {
    return
  }

  await driverPushTokenService.registerToken(token, Platform.OS)
  await AsyncStorage.setItem(storedTokenKey, token)
}

export function listenForPushNotifications(
  onEvent: (content: Notifications.NotificationContent) => void
): () => void {
  const receivedSubscription = Notifications.addNotificationReceivedListener((notification) => {
    onEvent(notification.request.content)
  })

  const responseSubscription = Notifications.addNotificationResponseReceivedListener((response) => {
    onEvent(response.notification.request.content)
  })

  return () => {
    receivedSubscription.remove()
    responseSubscription.remove()
  }
}
